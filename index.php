<?php

$time = microtime();

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

// Run scraping through pseudo cron
if (LDFF_SCRAPING_PSEUDO_CRON) {
	$last_run = intval(setting_read($db, 'pseudo_scraping_last_run', 0));
	$now = time();
	if ($now - $last_run > LDFF_SCRAPING_PSEUDO_CRON_DELAY) {
		setting_write($db, 'pseudo_scraping_last_run', $now);
		$report = scraping_run($db, LDFF_SCRAPING_TIMEOUT);
	}
}

// Fetch entries according to search params

$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM entry WHERE";
$empty_where = true;
$has_score = false;
if (isset($_GET['query']) && $_GET['query']) {
	$query = util_sanitize_query_param('query');
	$fulltext_part = "MATCH(author,title,description,platforms,type) AGAINST ('$query' IN NATURAL LANGUAGE MODE)"; // WITH QUERY EXPANSION
	$sql = "SELECT *, $fulltext_part AS score FROM entry WHERE ($fulltext_part OR uid = '$query')";
	$empty_where = false;
	$has_score = true;
}
if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
	if (!$empty_where) {
		$sql .= " AND";
	}
	$sql .= " MATCH(platforms) AGAINST('";
	foreach ($_GET['platforms'] as $raw_platform) {
		$sql .= util_sanitize($raw_platform).' ';
	}
	$sql .= "')";
	$empty_where = false;
}
if ($empty_where) {
	$sql .= " 1";
}

if ($has_score) {
	$sql .= " ORDER BY score DESC";
}
else {
	$sql .= " ORDER BY last_updated DESC";
}
$sql .= " LIMIT 10";
if (isset($_GET['page'])) {
	$page = intval(util_sanitize_query_param('page'));
	$sql .= " OFFSET " . (($page - 1) * 10);
}
//die($sql);

$entries = array();
$results = mysqli_query($db, $sql) or die('Failed to fetch entries: '.mysqli_error($db)); 
while ($row = mysqli_fetch_array($results)) {
	$row['picture'] = util_get_picture_path($row['uid']);
	$entries[] = $row;
}
$entry_count = db_select_single_value($db, 'SELECT FOUND_ROWS()');

// Build context

$context = array();
$context['competition'] = LDFF_COMPETITION_PAGE;
$context['ld_root'] = LDFF_SCRAPING_ROOT . '/' . LDFF_COMPETITION_PAGE . '?action=preview&';
$context['title'] = $empty_where ? 'Last updated entries' : 'Search results';
$context['entries'] = $entries;
$context['entry_count'] = $entry_count;
$context['search_query'] = util_sanitize_query_param('query');
if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
	$context['search_platforms'] = implode(', ', $_GET['platforms']);
}

mysqli_close($db);

$context['time'] = microtime() - $time;

// Templates rendering

function render($template_name) {
	global $mustache, $context;
	$template = $mustache->loadTemplate($template_name);
	echo $template->render($context);
}

// TODO Let ajax requests return a single, chosen template
if (isset($_GET['ajax'])) {
	$template_name = util_sanitize_query_param('ajax');
	render($template_name);
}
else {
	render('header');
	render('index');
	render('footer');
}

/*if (isset($report)) { // DEBUG
	echo "<pre>";
	print_r($report);
	echo "</pre>";
}*/

?>