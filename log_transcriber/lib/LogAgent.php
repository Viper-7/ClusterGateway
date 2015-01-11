<?php
class LogAgent {
	public $zmqContext;
	
	protected $incomingLogSocket;	// ZMQSocket
	protected $outgoingLogSocket;	// ZMQSocket
	protected $queue;				// ZMQPoll
	protected $queueIsWritable = false;

	protected $outboundQueue;		// SplQueue
	protected $accountingDB;		// PDO
	
	protected $logPort;
	protected $idsHost;
	protected $idsPort;
	
	protected $pingPort;
	protected $pingSocket;

	protected $webserverAccessLog;
	protected $webserverErrorLog;
	protected $firewallLog;
	protected $phpErrorLog;

	protected $accessLogSeparator = ',';
	protected $accessLogHeader = array('a','b','host','d','e','f','bytes_in','bytes_out');
	
	protected $errorLogSeparator = ',';
	protected $errorLogHeader = array('a','b','c');
	
	protected $accountingDBHost;
	protected $accountingDBUser;
	protected $accountingDBPass;
	
	protected $accountingNextRun = 0;
	protected $accountingInterval = 1800;		// Seconds
	
	protected $vhostBytes = array();			// ['foo.bar.com' => ['bytes_in' => 123, 'bytes_out' => 456]]
	
	private $disable_fifo = true;
	
	public function __construct(ClusterControl_Service_Config $config, $zmqContext = null) {
		if(is_null($zmqContext))
			$zmqContext = new ZMQContext();
		
		$this->zmqContext = $zmqContext;

		$this->outboundQueue = new SplQueue();
		
		$this->accountingDBHost = $config->accountingDBHost;
		$this->accountingDBUser = $config->accountingDBUser;
		$this->accountingDBPass = $config->accountingDBPass;
		
		$this->logPort = $config->logPort;
		$this->idsHost = $config->idsHost;
		$this->idsPort = $config->idsPort;
		
		$this->webserverAccessLog = $config->webserverAccessLog;
		$this->webserverErrorLog = $config->webserverErrorLog;
		$this->firewallLog = $config->firewallLog;
		$this->phpErrorLog = $config->phpErrorLog;
		
		$this->pingPort = $config->logPingPort;
	}
	
	public function log($message) {
		echo $message;
	}
	
	public function writeAccounting() {
		if(!$this->accountingDB)
			$this->connectDB();
		
		// Handle DB disconnections
		try {
			$this->accountingDB->exec();
		} catch (PDOException $e) {
			$this->connectDB();
		}
		
		// With PDO_mysql, these lines won't actually touch the database until execute() is called (EMULATE_PREPARES)
		$update = $this->accountingDB->prepare('UPDATE bandwidth SET bytes_in = bytes_in + :bytes_in, bytes_out = bytes_out + :bytes_out WHERE vhost = :vhost');
		$insert = $this->accountingDB->prepare('INSERT INTO bandwidth SET bytes_in = :bytes_in, bytes_out = :bytes_out, vhost = :vhost');
		
		foreach($this->vhostBytes as $vhost => $data) {
			$values = array('vhost' => $vhost);
			list($values['bytes_in'], $values['bytes_out']) = $data;

			try {
				$update->execute($values);
				
				if(!$update->rowCount()) {
					$insert->execute($values);
				}
			} catch (PDOException $e) {
				$this->log('Failed to write bandwidth to database ' . json_encode($values));
			}
		}
	}
	
	protected function connectDB() {
		$this->accountingDB = new PDO("mysql:host={$this->accountingDBHost}", $this->accountingDBUser, $this->accountingDBPass, array(PDO::EXCEPTION_MODE => PDO::ERRMODE_EXCEPTION));
	}
	
	public function setupSockets() {
		$this->incomingLogSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_SUB, "IncomingLogs");
		$this->incomingLogSocket->bind("tcp://*:{$this->logPort}");
		$this->incomingLogSocket->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "");
		
		$this->outgoingLogSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_PUB, "OutgoingLogs");
		$this->outgoingLogSocket->bind("tcp://{$this->idsHost}:{$this->idsPort}");

		$this->pingSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REP, "Ping");
		$this->pingSocket->bind("tcp://*:{$this->pingPort}");
	}
	
	public function setupQueue() {
		$queue = new ZMQPoll();
		
		$queue->add($this->incomingLogSocket, ZMQ::POLL_IN);
		$queue->add($this->pingSocket, ZMQ::POLL_IN);
		
		if(!$this->disable_fifo) {
			$queue->add(fopen($this->webserverAccessLog, 'r'), ZMQ::POLL_IN);
			$queue->add(fopen($this->webserverErrorLog, 'r'), ZMQ::POLL_IN);
			$queue->add(fopen($this->firewallLog, 'r'), ZMQ::POLL_IN);
			$queue->add(fopen($this->phpErrorLog, 'r'), ZMQ::POLL_IN);
		}
		
		$this->queue = $queue;
	}
	
	public function setWebserverAccessLog($path) {
		$this->webserverAccessLog = $path;
	}
	
	public function setWebserverErrorLog($path) {
		$this->webserverErrorLog = $path;
	}
	
	public function setFirewallLog($path) {
		$this->firewallLog = $path;
	}
	
	public function setPHPErrorLog($path) {
		$this->phpErrorLog = $path;
	}
	
	public function listen() {
		if(!$this->incomingLogSocket) {
			$this->setupSockets();
			$this->setupQueue();
		}

		while(true) {
			if($this->queue->poll($readable, $writable, 60000)) { // Timeout of 1 minute
				if($writable) {
					$output = $this->outboundQueue->dequeue();
					
					foreach($writable as $stream) {
						$stream->send($output);
					}	

					if(!count($this->outboundQueue)) {
						$this->queueIsWritable = false;
						$this->queue->remove($this->outgoingLogSocket);
					}
						
				}

				if($readable) {
					foreach($readable as $stream) {
						if($stream === $this->pingSocket) {
							if(($data = $stream->read()) != 'PING')
								$this->log('Garbage data on ping socket ' . $data);
							else 
								$stream->send('PONG');
						} else {
							$data = $stream->read();
							
							switch($stream) {
								case $this->webserverAccessLog:
									$channel = 'Log.Webserver.Access';
									$parts = array_combine($this->accessLogHeader, explode($this->accessLogSeparator, $data, count($this->accessLogHeader)) + $this->accessLogHeader);
									
									if(isset($this->vhostBytes[$parts['host']])) {
										$this->vhostBytes[$parts['host']]['bytes_in'] += $parts['bytes_in'];
										$this->vhostBytes[$parts['host']]['bytes_out'] += $parts['bytes_out'];
									} else {
										$this->vhostBytes[$parts['host']]['bytes_in'] = $parts['bytes_in'];
										$this->vhostBytes[$parts['host']]['bytes_out'] = $parts['bytes_out'];
									}
									
									$data = "{$channel}: " . json_encode($parts);
									break;
								
								case $this->webserverErrorLog:
									$channel = 'Log.Webserver.Error';
									$parts = array_combine($this->errorLogHeader, explode($this->errorLogSeparator, $data, count($this->errorLogHeader)) + $this->errorLogHeader);
									
									$data = "{$channel}: " . json_encode($parts);
									break;
								
								case $this->firewallLog:
									$channel = 'Log.Firewall';
									$parts = explode(" ", $data);
									$log = array();
									
									foreach($parts as $part) {
										if(strpos($part, '=') !== FALSE) {
											list($key, $value) = explode('=', $part, 2);
											
											$log[$key] = $value;
										} else {
											$log[$part] = $part;
										}
									}
									
									$data = "{$channel}: " . json_encode($log);
									break;
								
								case $this->phpErrorLog:
									$channel = 'Log.ClusterControl.Error';
									$data = "{$channel}: {$data}";
									break;
								
								default:
									if(substr($data, 0, 3) !== 'Log') {
										$data = "Log.ClusterControl.Unknown: " . json_encode($data);
									}
									
									list($channel, $payload) = explode(': ', $data, 2) + array('','');
							}
							
							$this->outboundQueue->enqueue($data);
							
							if(!$this->queueIsWritable)
								$this->queue->add($this->outgoingLogSocket, ZMQ::POLL_OUT);
						}
					}
				}
			}

			if($this->accountingNextRun <= time()) {
				$this->writeAccounting();
				$this->accountingNextRun = time() + $this->accountingInterval;
			}
		}
	}
}