<?php
	$configHost = '127.0.0.1';
	$configPort = '5556';
	
	session_start();
	
	if(isset($_SESSION['correct_answer']) && isset($_GET['answer'])) {
		if($_GET['answer'] == $_SESSION['correct_answer']) {
			$zmqContext = new ZMQContext();
			$configSocket = new ZMQSocket($this->zmqContext, ZMQ::SOCKET_REQ, "Config");
			$configSocket->connect("tcp://{$configHost}:{$configPort}");
			$configSocket->send("EndCaptcha: {$_SERVER['REMOTE_ADDR']}");
			header('Location: ' . $_SERVER['REQUEST_URI']);
			die();
		}
	}
	
	$answers = array('foo','bar','baz','bob');
	$question = 'cup';
	$_SESSION['correct_answer'] = 'bob';
?><p style="height: 150px">&nbsp;</p><p align="center">To continue, please click on the <?php echo $question ?><br><br><?php foreach($answers as $id => $answer): ?><a href="?answer=<?php echo $id ?>"><img src="images/<?php echo htmlentities($answer, ENT_QUOTES) ?>.png"/></a></p>