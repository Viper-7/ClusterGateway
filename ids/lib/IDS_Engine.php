<?php
class IDS_Engine {
	protected $zmqContext;
	protected $queue;
	protected $phpIDS;
	
	protected $outgoingLogSocket;
	protected $incomingLogSocket;
	protected $configSocket;
	
	protected $pingPort;
	protected $pingSocket;

	protected $outgoingLogHost;
	protected $outgoingLogPort;
	
	protected $incomingLogPort;
	
	protected $configHost;
	protected $configPort;
	
	public function __construct(ClusterControl_Service_Config $config, $zmqContext = null) {
		if(is_null($zmqContext))
			$zmqContext = new ZMQContext();
		
		$this->zmqContext = $zmqContext;
		
		$init = IDS\Init::init(dirname(__FILE__) . '/PHPIDS/lib/IDS/Config/Config.ini.php');
		
		$init->config['General']['filter_type'] = 'json';
		$init->config['General']['filter_path'] = dirname(__FILE__) . '/PHPIDS/lib/IDS/default_filter.json';
		$init->config['General']['base_path'] = null;
		$init->config['General']['use_base_path'] = false;
		$init->config['General']['tmp_path'] = sys_get_temp_dir();
		$init->config['Caching']['caching'] = 'none';
		
		$ids = new IDS\Monitor($init);
		$this->phpIDS = $ids;
		
		$this->outgoingLogHost = $config->logHost;
		$this->outgoingLogPort = $config->logPort;
		$this->incomingLogPort = $config->idsPort;
		$this->configHost = $config->configHost;
		$this->configPort = $config->configIDSPort;
		
		$this->pingPort = $config->idsPingPort;
	}
	
	public function setupSockets() {
		$this->incomingLogSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_SUB, "IncomingLogs");
		$this->incomingLogSocket->bind("tcp://*:{$this->incomingLogPort}");
		$this->incomingLogSocket->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "");
		
		$this->outgoingLogSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_PUB, "OutgoingLogs");
		$this->outgoingLogSocket->connect("tcp://{$this->outgoingLogHost}:{$this->outgoingLogPort}");
		
		$this->configSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REQ, "Config");
		$this->configSocket->connect("tcp://{$this->configHost}:{$this->configPort}");

		$this->pingSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REP, "Ping");
		$this->pingSocket->bind("tcp://*:{$this->pingPort}");
	}
	
	public function setupQueue() {
		$queue = new ZMQPoll();
		
		$queue->add($this->incomingLogSocket, ZMQ::POLL_IN);
		$queue->add($this->pingSocket, ZMQ::POLL_IN);
		
		$this->queue = $queue;
	}
	
	public function log($message) {
		echo $message; 
	}
	
	public function listen() {
		if(!$this->incomingLogSocket) {
			$this->setupSockets();
			$this->setupQueue();
		}

		while(true) {
			if($this->queue->poll($readable, $writable)) {
				if($readable) {
					foreach($readable as $stream) {
						if($stream === $this->pingSocket) {
							if(($data = $stream->read()) != 'PING')
								$this->log('Garbage data on ping socket ' . $data);
							else
								$stream->send('PONG');
						} else {
							$data = $stream->read();
							
							$request = new IDS_Request($this->phpIDS);
							$request->parse($data);
							
							if($request->isAbuse()) {
								$this->outgoingLogSocket->send("Log.IDS.Abuse: {$request->ip},{$request->abuseScore}");
								
								if($level = $request->takeAction()) {
									switch($level) {
										case 3: // Upstream router ban
											$this->configSocket->send("UpstreamBan: " . $request->ip);
											$this->outgoingLogSocket->send("Log.IDS.UpstreamBan: {$request->ip}");
											
											break;
										case 2:	// IPTables
											$this->configSocket->send("FirewallBan: " . $request->ip);
											$this->outgoingLogSocket->send("Log.IDS.FirewallBan: {$request->ip}");
											
											break;
										case 1: // Randomize Status
											$this->configSocket->send("StartMangleStatus: " . $request->ip);
											$this->outgoingLogSocket->send("Log.IDS.StartMangleStatus: {$request->ip}");
											
											break;
										default: // Captcha
											$this->configSocket->send("StartCaptcha: " . $request->ip);
											$this->outgoingLogSocket->send("Log.IDS.StartCaptcha: {$request->ip}");
									}
								}
							}
						}
					}
				}
			}
		}
	}
}
