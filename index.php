<?php

$time = microtime(true);

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

// Run scraping through pseudo cron
if (LDFF_SCRAPING_PSEUDO_CRON) {
	$last_run = intval(setting_read($db, 'pseudo_scraping_last_run', 0));
	$now = time();
	if ($now - $last_run > LDFF_SCRAPING_PSEUDO_CRON_DELAY) {
		setting_write($db, 'pseudo_scraping_last_run', $now);
		$report = scraping_run($db);
	}
}

// Pages

function init_context($db) {
	global $time;

	$context = array();
	$context['competition'] = LDFF_COMPETITION_PAGE;
	$context['ld_root'] = LDFF_SCRAPING_ROOT . '/' . LDFF_COMPETITION_PAGE . '?action=preview&';
	$context['oldest_entry_updated'] = db_select_single_value($db, "SELECT last_updated FROM entry ORDER BY last_updated LIMIT 1");
	$context['time'] = microtime(true) - $time;
	return $context;
}

function render($template_name, $context) {
	global $mustache;
	$template = $mustache->loadTemplate($template_name);
	echo $template->render($context);
}

function _page_details_list_comments($db, $where_clause) {
	$results = mysqli_query($db, "SELECT comment.*, entry.author FROM comment, entry 
			WHERE $where_clause ORDER BY `date` DESC, `order` DESC")
		or die('Failed to fetch comments: '.mysqli_error($db)); 
	$comments = array();
	while ($comment = mysqli_fetch_array($results)) {
		$comments[] = $comment;
	}
	return $comments;
}

function page_details($db) {
	$uid = intval(util_sanitize_query_param('uid'));

	// Force refresh
	if (isset($_GET['refresh'])) { // TODO Prevent abuse
		scraping_refresh_entry($db, $uid);
	}

	// Gather entry info
	$results = mysqli_query($db, "SELECT * FROM entry WHERE uid = ".$uid)
		or die('Failed to fetch entry: '.mysqli_error($db)); 
	$entry = mysqli_fetch_array($results);
	$entry['picture'] = util_get_picture_path($entry['uid']);
	$entry['received'] = _page_details_list_comments($db,
		"comment.uid_author = entry.uid AND uid_entry = $uid and uid_author != $uid");
	$entry['given'] = _page_details_list_comments($db,
		"comment.uid_entry = entry.uid AND uid_author = $uid and uid_entry != $uid");
	$entry['given_average'] = score_average($entry['given']);

	$results = mysqli_query($db, "SELECT comment2.uid_author, entry.author FROM comment comment1, comment comment2, entry 
			WHERE comment1.uid_author = $uid
			AND comment2.uid_author != $uid
			AND comment1.uid_entry = comment2.uid_author
			AND comment2.uid_entry = $uid
			AND entry.uid = comment2.uid_author ORDER BY comment1.date DESC")
		or die('Failed to fetch comments: '.mysqli_error($db)); 
	$friends = array();
	while ($friend = mysqli_fetch_array($results)) {
		$friends[] = $friend;
	}
	$entry['friends_rows'] = util_array_chuck_into_object($friends, 5, 'friends'); // split for rendering
	$entry['friends_count'] = count($friends);

	// Build context
	$context = init_context($db);
	$context['entry'] = $entry;

	// Render
	render('header', $context);
	render('details', $context);
	render('footer', $context);
}

function page_browse($db) {

	// Build query according to search params

	$empty_where = true;
	$not_coolness_search = false;

	$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM entry WHERE";
	if (isset($_GET['query']) && $_GET['query']) {
		$query = util_sanitize_query_param('query');
		$fulltext_part = "MATCH(author,title,description,platforms,type) AGAINST ('$query' IN BOOLEAN MODE)"; // WITH QUERY EXPANSION
		$sql = "SELECT * FROM entry WHERE ($fulltext_part OR uid = '$query')"; /*, $fulltext_part AS score*/
		$empty_where = false;
		$not_coolness_search = true;
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
	$sorting = 'coolness';
	if (isset($_GET['sorting'])) {
		$sorting = util_sanitize_query_param('sorting');
	}
	switch ($sorting) {
		case 'random': $sql .= " ORDER BY RAND()"; break;
		case 'comments': $sql .= " ORDER BY comments_received, last_updated DESC"; break;
		default: $sql .= " ORDER BY coolness DESC, last_updated DESC";
	}
	$sql .= " LIMIT ".LDFF_PAGE_SIZE;
	$page = 1;
	if (isset($_GET['page'])) {
		$page = intval(util_sanitize_query_param('page'));
		$sql .= " OFFSET " . (($page - 1) * LDFF_PAGE_SIZE);
	}

	// Fetch entries

	$entries = array();
	$results = mysqli_query($db, $sql) or die('Failed to fetch entries: '.mysqli_error($db)); 
	while ($row = mysqli_fetch_array($results)) {
		$row['picture'] = util_get_picture_path($row['uid']);
		$entries[] = $row;
	}
	$entry_count = db_select_single_value($db, 'SELECT FOUND_ROWS()');

	// Build context

	$context = init_context($db);
	$context['title'] = $not_coolness_search ? 'Search results' : 'These entries need feedback!';
	$context['page'] = $page;
	$context['entries'] = $entries;
	if ($not_coolness_search) {
		$context['entry_count'] = $entry_count;
	}
	$context['entries_found'] = $entry_count > 0;
	$context['search_query'] = util_sanitize_query_param('query');
	$context['search_sorting'] = $sorting;
	if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
		$context['search_platforms'] = implode(', ', $_GET['platforms']);
	}
	if (isset($_GET['page'])) {
		$context['entries_only'] = true;
	}

	// Render

	if (isset($_GET['ajax'])) {
		$template_name = util_sanitize_query_param('ajax');
		render($template_name, $context);
	}
	else {
		render('header', $context);
		render('browse', $context);
		render('footer', $context);
	}
}

function page_faq($db) {
	$context = init_context($db);
	render('header', $context);
	render('faq', $context);
	render('footer', $context);
}

// Choose page
if (isset($_GET['uid'])) {
	page_details($db);
}
else if (isset($_GET['p']) == 'faq') {
	page_faq($db);
}
else {
	page_browse($db);
}

mysqli_close($db);

// DEBUG
/*if (isset($report)) { 
	echo "<pre>";
	print_r($report);
	echo "</pre>";
}*/

?>