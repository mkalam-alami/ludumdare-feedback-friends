<html>
<head>
	<meta charset="utf-8" />
 <!-- <meta http-equiv="refresh" content="1">-->
</head>
<body>

<?php

require_once('includes/init.php');

util_require_admin();

if (LDFF_SCRAPING_ENABLED) {

	set_time_limit(LDFF_SCRAPING_TIMEOUT + 30);

	$db = db_connect();

	echo '<pre>';
  //print_r(_scraping_build_author_cache($db, 9405));
  $author_cache = _scraping_build_author_cache($db, 9405);
  print_r(_scraping_run_step_entry($db, 9405, 15786, $author_cache));
	//print_r(ld_fetch_entry($db, 15312));
	//print_r(scraping_run($db));
	echo '</pre>';

	mysqli_close($db);

} else {
	log_info("Scraping disabled, nothing to do.");
}

?>

</body>
</html>