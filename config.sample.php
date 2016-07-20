<?php

// Competition config
define('LDFF_ACTIVE_EVENT_ID', 'ludum-dare-35');
$events = array(
		'ludum-dare-35' => 'Ludum Dare 35'
	);

// Database configuration
define('LDFF_ROOT_URL', '/');
define('LDFF_MYSQL_HOST', 'localhost');
define('LDFF_MYSQL_USERNAME', 'root');
define('LDFF_MYSQL_PASSWORD', '');
define('LDFF_MYSQL_DATABASE', 'ldff');

// Scraping config. Prefer using an actual crontab over pseudo crons for performance (call "php scraping.php").
define('LDFF_SCRAPING_ENABLED', true);
define('LDFF_SCRAPING_TIMEOUT', 1); // max execution duration, in seconds
define('LDFF_SCRAPING_SLEEP', 0); // time to sleep betwen requests, in seconds
define('LDFF_SCRAPING_FRONTPAGE_MAX_AGE', 300); // max age after which scraping is forced for the 9 frontpage entries, in seconds.
define('LDFF_SCRAPING_PSEUDO_CRON', false);
define('LDFF_SCRAPING_PSEUDO_CRON_DELAY', 60); // delay between executions, in seconds

// Logging
define('LDFF_LOG_ENABLED', true);
define('LDFF_LOG_PATH', __DIR__ . '/ldff.log');

// Production
define('LDFF_PRODUCTION', false); // PHP logs errors only, require admin password to run things
define('LDFF_EMERGENCY_MODE', false); // No search, no entry details
define('LDFF_ADMIN_PASSWORD', 'changeme'); // Add a "?p=..." param to admin requests in production
define('LDFF_UID_BLACKLIST', ''); // Example: '234567,876543'. Comments are worth 0p, entry scraping is disabled
define('LDFF_GOOLE_ANALYTICS_ID', '');

?>
