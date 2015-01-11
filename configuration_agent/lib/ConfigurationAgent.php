<?php
class ConfigurationAgent {
	protected $zmqContext;
	protected $queue;
	protected $worker;
	
	protected $requestSocket;
	protected $pullSocket;
	protected $idsRequestSocket;
	
	protected $pingPort;
	protected $pingSocket;

	protected $requestPort;
	protected $pullPort;
	protected $idsRequestPort;
	
	public function __construct(ClusterControl_Service_Config $config, $zmqContext = null) {
		if(is_null($zmqContext))
			$zmqContext = new ZMQContext();
		
		$this->zmqContext = $zmqContext;
		$this->worker = new ConfigurationAgent_Worker();
		
		$this->requestPort = $config->configRequestPort;
		$this->pullPort = $config->configPullPort;
		$this->idsRequestPort = $config->configIDSPort;
		
		$this->pingPort = $config->configPingPort;
	}
	
	public function setupSockets() {
		$this->requestSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REP, "IncomingRequest");
		$this->requestSocket->bind("tcp://*:{$this->requestPort}");
		
		$this->pullSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_PULL, "IncomingPush");
		$this->pullSocket->bind("tcp://*:{$this->pullPort}");

		$this->idsRequestSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REP, "IncomingIDSRequest");
		$this->idsRequestSocket->bind("tcp://*:{$this->idsRequestPort}");

		$this->pingSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REP, "Ping");
		$this->pingSocket->bind("tcp://*:{$this->pingPort}");
	}
	
	public function setupQueue() {
		$queue = new ZMQPoll();
		
		$queue->add($this->requestSocket, ZMQ::POLL_IN);
		$queue->add($this->pullSocket, ZMQ::POLL_IN);
		$queue->add($this->pingSocket, ZMQ::POLL_IN);
		
		$this->queue = $queue;
	}
	
	public function log($message) {
		echo $message; 
	}
	
	public function listen() {
		if(!$this->requestSocket) {
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
							$bytes = strlen($data);
							list($command, $payload) = explode(': ', $data, 2) + array('','');
							
							if(method_exists($this->worker, $command)) {
								$result = $this->worker->$command($payload);
								$stream->send($result);
							} else {
								$stream->send("Error: Command {$command} not found");
							}
						}
					}
				}
			}
		}
	}
}