<?php
class MonitoringAgent {
	protected $zmqContext;
	
	protected $sockets = array();
	protected $config;
	
	protected $socketTimeout = 10000; // in Milliseconds
	protected $pingTimeout   = 60; // in Seconds - Must be at least count(sockets) * socketTimeout / 1000
	
	protected $lastContact = array();
	
	public function __construct(ClusterControl_Service_Config $config, $zmqContext = null) {
		if(is_null($zmqContext))
			$zmqContext = new ZMQContext();
		
		$this->zmqContext = $zmqContext;
		$this->config = $config;
	}
	
	public function setupSockets() {
		// Config Agent
		$socket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REQ, "ConfigPing");
		$socket->connect("tcp://{$config->configHost}:{$config->configPingPort}");
		$this->sockets['Configuration_Agent'] = $socket;
		
		// Log Agent
		$socket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REQ, "LogPing");
		$socket->connect("tcp://{$config->logHost}:{$config->logPingPort}");
		$this->sockets['Log_Agent'] = $socket;
		
		// IDS
		$socket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REQ, "IDSPing");
		$socket->connect("tcp://{$config->idsHost}:{$config->idsPingPort}");
		$this->sockets['IDS'] = $socket;
		
		$this->lastContact = array();
		foreach($this->sockets as $key => $socket) {
			$this->lastContact[$key] = 0;
		}
	}
	
	public function log($message) {
		echo $message;
	}
	
	protected function handleFailure($service, $reason) {
		foreach(glob(__DIR__ . '../actions/*.php') as $script) {
			$func = include $script;
			
			if(!is_callable($func)) {
				$this->log("Uncallable action script: {$script}");
				continue;
			}
			
			$func($service, $reason);
		}
	}
	
	public function listen() {
		if(empty($this->sockets)) {
			$this->setupSockets();
		}

		$socketTimeoutSeconds = $this->socketTimeout / 1000;

		while(true) {
			$start = time();
			
			foreach($this->sockets as $name => $socket) {
				try {
					$socket->send('PING');
				} catch (Exception $e) {
					$this->handleFailure($name, $e->getMessage());
				}
			}
			
			foreach($this->sockets as $name => $socket) {
				try {
					$poll = new ZMQ_Poll();
					$poll->add($socket, ZMQ::POLL_IN);
					
					if(!$poll->poll($recv, $write, $this->socketTimeout)) {
						throw new Exception('Socket timeout');
					} else {
						$data = $socket->recv();
						
						if($data != 'PONG')
							throw new Exception('Invalid ping response');
						else
							$this->lastContact[array_search($socket, $this->sockets)] = time();
					}
				} catch (Exception $e) {
					$this->handleFailure($name, $e->getMessage());
				}
			}
			
			$target = $time - $this->pingTimeout;
			
			foreach($this->lastContact as $key => $value) {
				if($value < $target)
					$this->handleFailure($key, 'Ping timeout');
			}
			
			if(($diff = ($start + $socketTimeoutSeconds) - time()) > 0) {
				sleep($diff);
			}
		}
	}
}
