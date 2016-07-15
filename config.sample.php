<?php

// Competition config
define('LDFF_ACTIVE_EVENT_ID', 'ludum-dare-35');
$events = array(
		'ludum-dare-35' => 'Ludum Dare 35'
	);

// Database configuration
define('LDFF_MYSQL_HOST', 'localhost');
define('LDFF_MYSQL_USERNAME', 'root');
define('LDFF_MYSQL_PASSWORD', '');
define('LDFF_MYSQL_DATABASE', 'ldff');

// Scraping config. Prefer using an actual crontab for performance (call "php scraping.php").
define('LDFF_SCRAPING_ENABLED', true);
define('LDFF_SCRAPING_PSEUDO_CRON', false);
define('LDFF_SCRAPING_PSEUDO_CRON_DELAY', 60); // delay between executions, in seconds
define('LDFF_SCRAPING_TIMEOUT', 1); // max execution duration, in seconds
define('LDFF_SCRAPING_SLEEP', 0); // time to sleep betwen requests, in seconds

// Logging
define('LDFF_LOG_ENABLED', true);
define('LDFF_LOG_PATH', __DIR__ . '/ldff.log');

// Production mode (PHP logs errors only, prevent messing with the install script)
define('LDFF_PRODUCTION', false);

?>