#!/usr/bin/env php
<?php
	if(!class_exists('IDS_Engine', true))
		spl_autoload_register(function($classname) { if(file_exists($file = "lib/{$classname}.php")) { include $file; return true; } });
	
	if(!class_exists('IDS\\Init', true))
		spl_autoload_register(function($class) {
			$path = __DIR__ . '/lib/PHPIDS/lib/';

			if(($namespace = strrpos($class = ltrim($class, '\\'), '\\')) !== false) {
				$path .= strtr(substr($class, 0, ++$namespace), '\\', '/');
			}

			require_once($path . strtr(substr($class, $namespace), '_', '/') . '.php');
		});
	
	include '../config.php';
	
	$config = new ClusterControl_Service_Config();
	
	$ids = new IDS_Engine($config);
	$ids->listen();
?>