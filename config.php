<?php

// Competition config
define('LDFF_COMPETITION_PAGE', 'ludum-dare-35');

// Database configuration
define('LDFF_MYSQL_HOST', 'localhost');
define('LDFF_MYSQL_USERNAME', 'root');
define('LDFF_MYSQL_PASSWORD', '');
define('LDFF_MYSQL_DATABASE', 'ldff');

// Scraping config. Prefer using an actual crontab for performance (call "php scraping.php").
define('LDFF_SCRAPING_PSEUDO_CRON', false);
define('LDFF_SCRAPING_PSEUDO_CRON_DELAY', 0); // delay between executions, in seconds
define('LDFF_SCRAPING_TIMEOUT', 2); // max execution duration, in seconds
define('LDFF_SCRAPING_SLEEP', 0.5); // time to sleep betwen requests, in seconds

// Production mode (PHP logs errors only, prevent messing with the install script)
define('LDFF_PRODUCTION', false); // TODO

?>