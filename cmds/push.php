<?php
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Provider.php');
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');
if (!empty($config['preparse'])) {
	$runAfterParse = true;
	include(__DIR__ . DIRECTORY_SEPARATOR . 'parse.php');
}

$provider = new psesd\serverReporter\Provider($config);
if ($provider->push()) {
	exit(0);
}
exit(1);