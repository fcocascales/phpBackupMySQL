<?php // TEST

	require_once 'BackupMySQL.php';

	$connection = [
		'host'=> "localhost",
		'database'=> "bd_neptuno",
		'user'=> "root",
		'password'=> "cascales",
	];
	//$tables = ['*', 'albaranes', 'pedidos'];
	$tables = ['pedidos'];
	$show = ['TABLES', 'DATA'];
	$backup = new BackupMySQL($connection, $tables, $show);
	$backup->setFolder("backups");
	$backup->setName('pedidos');
	//$backup->test();
	$backup->run();
	$backup->zip();
	$backup->download();
