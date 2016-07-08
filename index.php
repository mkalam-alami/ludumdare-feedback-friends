<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

// Run scraping through pseudo cron
if (LDFF_SCRAPING_PSEUDO_CRON) {
	$last_run = intval(setting_read($db, 'pseudo_scraping_last_run', 0));
	$now = time();
	if ($now - $last_run > LDFF_SCRAPING_PSEUDO_CRON_DELAY) {
		setting_write($db, 'pseudo_scraping_last_run', $now);
		$report = scraping_run($db, LDFF_SCRAPING_PSEUDO_CRON_TIMEOUT);
	}
}

echo "<h1>hello world</h1>";

echo "<pre>";
print_r($report);
echo "</pre>";

mysqli_close($db);

?>

