<?php

// Global constants

define('LDFF_VERSION', 1);

// Includes

require_once('/config.php');
require_once('db.php');
require_once('setting.php');

// PHP Errors level

if (LDFF_PRODUCTION) {
	error_reporting(E_ERROR);
}
else {
	error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
}

?>

