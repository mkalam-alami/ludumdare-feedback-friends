<?php

// Production mode (PHP logs errors only, prevent messing with the install script)
define('LDFF_PRODUCTION', false);

// Database configuration
define('LDFF_MYSQL_HOST', 'localhost');
define('LDFF_MYSQL_USERNAME', 'root');
define('LDFF_MYSQL_PASSWORD', '');
define('LDFF_MYSQL_DATABASE', 'ldff');

// Scraping config. Prefer using an actual crontab for performance (call "php scraping.php").
define('LDFF_SCRAPING_PSEUDO_CRON', true);
define('LDFF_SCRAPING_PSEUDO_CRON_EXPRESSION', '0 * * * * *');

?>

