<?php

// Global constants

define('LDFF_DB_VERSION', 5);
define('LDFF_JS_CSS_VERSION', 7); // Change the value to force a JS/CSS refresh on each browser
define('LDFF_PAGE_SIZE', 9);
define('LDFF_FORCE_REFRESH_DELAY', 5*60);
define('LD_WEB_ROOT', 'https://ldjam.com');
define('LD_SCRAPING_ROOT', 'http://api.ludumdare.com/vx/');
define('LD_OLD_WEB_ROOT', 'http://ludumdare.com/compo/');


// Includes

require_once(__DIR__ . '/util.php');
require_once(__DIR__ . '/../vendor/autoload.php'); // TODO Optimize imports
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/log.php');
require_once(__DIR__ . '/db.php');
require_once(__DIR__ . '/cache.php');
require_once(__DIR__ . '/setting.php');
require_once(__DIR__ . '/ludumdare.php');
require_once(__DIR__ . '/score.php');
require_once(__DIR__ . '/scraping.php');

// PHP Errors level

if (LDFF_PRODUCTION) {
	error_reporting(E_ERROR);
} else {
	error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
}

// Templating

$mustache_loader_options = array('extension' => '.html');
$mustache = new Mustache_Engine(array(
    	'loader' => new Mustache_Loader_FilesystemLoader(__DIR__.'/../templates', $mustache_loader_options)
    ));

?>
