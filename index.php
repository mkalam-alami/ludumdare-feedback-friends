<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

// Run scraping through pseudo cron
if (LDFF_SCRAPING_PSEUDO_CRON) {
	$last_run = intval(setting_read($db, 'pseudo_scraping_last_run', 0));
	$now = time();
	if ($now - $last_run > LDFF_SCRAPING_PSEUDO_CRON_DELAY) {
		setting_write($db, 'pseudo_scraping_last_run', $now);
		$report = scraping_run($db, LDFF_SCRAPING_TIMEOUT);

		/*echo "<pre>";
		print_r($report);
		echo "</pre>";*/
	}
}

mysqli_close($db);

//print_r(http_fetch_entry(55626)); // DEBUG

$template = $mustache->loadTemplate('header');
echo $template->render(array('test' => LDFF_COMPETITION_PAGE));

?>