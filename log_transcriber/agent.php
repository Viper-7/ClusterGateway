#!/usr/bin/env php
<?php
	if(!class_exists('LogAgent', true))
		spl_autoload_register(function($classname) { if(file_exists($file = "lib/{$classname}.php")) { include $file; return true; } });
	
	include '../config.php';
	
	$config = new ClusterControl_Service_Config();

	$agent = new LogAgent($config);
	$agent->listen();
?>