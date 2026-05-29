<?php
declare(strict_types=1);

$lockFile = __DIR__ . '/install.lock';

if(!is_file($lockFile)){
	header('Location: installer.php');
	exit;
}

header('Location: login.php');
exit;
