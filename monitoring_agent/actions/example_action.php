<?php
return function($serviceName, $failureReason) {
	mail('viper7@viper-7.com', "ClusterControl::{$serviceName} is not responding", "Exception details: {$failureReason}");
};