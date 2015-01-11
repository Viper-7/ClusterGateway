<?php
use IDS\Init;
use IDS\Monitor;

class IDS_Request {
	protected $rawRequest;
	protected $ids;
	
	public function __construct($ids) {
		$this->ids = $ids;
	}
	
	public function parse($data) {
		$this->rawRequest = $data;

		var_dump($data);
		die();
		
		try {
			$result = $ids->run($request);
			
			if (!$result->isEmpty()) {
				echo $result;
				die();
			}
		} catch (Exception $e) {
			var_dump($e->getMessage());
			die();
		}
	}
	
	public function isAbuse() {
		return true;
	}
	
	public function takeAction() {
		return 1;
	}
}