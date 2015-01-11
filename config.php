<?php
class ClusterControl_Service_Config {
	public $logHost = '127.0.0.1';
	public $logPort = '5551';
	
	public $idsHost = '127.0.0.1';
	public $idsPort = '5552';

	public $configHost = '127.0.0.1';
	public $configRequestPort = '5553';
	public $configPullPort = '5554';
	public $configIDSPort = '5556';

	public $accountingDBHost = '127.0.0.1';
	public $accountingDBUser = 'accounting';
	public $accountingDBPass = 'securepass';

	public $webserverAccessLog = '/usr/local/nginx/logs/access.log';
	public $webserverErrorLog = '/usr/local/nginx/logs/error.log';
	public $firewallLog = '/var/log/iptables.log';
	public $phpErrorLog = '/var/log/php-fpm.log';
	
	public $logPingPort = '5561';
	public $idsPingPort = '5562';
	public $configPingPort = '5563';
}