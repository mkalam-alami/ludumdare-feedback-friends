<?php

$time = microtime(true);

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

// Run scraping through pseudo cron
if (LDFF_SCRAPING_ENABLED && LDFF_SCRAPING_PSEUDO_CRON) {
	$last_run = intval(setting_read($db, 'pseudo_scraping_last_run', 0));
	$now = time();
	if ($now - $last_run > LDFF_SCRAPING_PSEUDO_CRON_DELAY) {
		setting_write($db, 'pseudo_scraping_last_run', $now);
		$report = scraping_run($db);
	}
}

// Detect current event

$event_id = LDFF_ACTIVE_EVENT_ID; // TODO Support multiple events through a query param
if (isset($_GET['event'])) {
	$event_id = util_sanitize_query_param('event');
}

// Pages

function init_context($db) {
	global $time, $events, $event_id;

	$events_render = array();
	foreach ($events as $id => $label) {
		$events_render[] = array(
			'id' => $id,
			'label' => $label
			);
	}

	$context = array();
	$context['event_title'] = isset($events[$event_id]) ? $events[$event_id] : 'Unknown event';
	$context['events'] = $events_render;
	$context['ld_root'] = LDFF_SCRAPING_ROOT . '/' . $event_id . '?action=preview&';
	$context['oldest_entry_updated'] = db_select_single_value($db, "SELECT last_updated FROM entry WHERE event_id = '$event_id' ORDER BY last_updated LIMIT 1");
	$context['time'] = round(microtime(true) - $time, 3);
	return $context;
}

function render($template_name, $context) {
	global $mustache;
	$template = $mustache->loadTemplate($template_name);
	echo $template->render($context);
}

function _page_details_list_comments($db, $join_clause, $where_clause) {
	global $event_id;

	$results = mysqli_query($db, "SELECT comment.*, entry.author FROM comment 
			LEFT JOIN entry ON $join_clause AND entry.event_id = '$event_id'
			WHERE comment.event_id = '$event_id' 
			AND $where_clause 
			ORDER BY `date` DESC, `order` DESC")
		or log_error_and_die('Failed to fetch comments', mysqli_error($db)); 
	$comments = array();
	while ($comment = mysqli_fetch_array($results)) {
		if (!$comment['author']) { // Not an entry author for this event
			$comment['uid_author'] = null;
		}
		$comments[] = $comment;
	}
	return $comments;
}

function _prepare_entry_for_rendering($entry) {
	global $event_id;

	$entry['picture'] = util_get_picture_path($event_id, $entry['uid']);
	$entry['type'] = util_format_type($entry['type']);
	$entry['platforms'] = util_format_platforms($entry['platforms']);
	return $entry;
}

function page_details($db) {
	global $event_id;

	$uid = intval(util_sanitize_query_param('uid'));

	// Force refresh
	if (isset($_GET['refresh'])) { // TODO Prevent abuse
		scraping_refresh_entry($db, $uid);
	}

	// Gather entry info
	$results = mysqli_query($db, "SELECT * FROM entry WHERE event_id = '$event_id' AND uid = ".$uid)
		or log_error_and_die('Failed to fetch entry', mysqli_error($db)); 
	$entry = mysqli_fetch_array($results);
	$entry['picture'] = util_get_picture_path($event_id, $entry['uid']);
	$entry['received'] = _page_details_list_comments($db,
		"comment.uid_author = entry.uid", "comment.uid_entry = $uid AND comment.uid_author != $uid");
	$entry['given'] = _page_details_list_comments($db,
		"comment.uid_entry = entry.uid", "comment.uid_author = $uid AND comment.uid_entry != $uid");
	$entry['given_average'] = score_average($entry['given']);

	$results = mysqli_query($db, "SELECT comment2.uid_author, entry.author FROM comment comment1, comment comment2, entry 
			WHERE comment1.event_id = '$event_id' 
			AND comment2.event_id = '$event_id' 
			AND comment1.uid_author = $uid
			AND comment2.uid_author != $uid
			AND comment1.uid_entry = comment2.uid_author
			AND comment2.uid_entry = $uid
			AND entry.uid = comment2.uid_author ORDER BY comment1.date DESC")
		or log_error_and_die('Failed to fetch comments', mysqli_error($db)); 
	$friends = array();
	while ($friend = mysqli_fetch_array($results)) {
		$friends[] = $friend;
	}
	$entry['friends_rows'] = util_array_chuck_into_object($friends, 5, 'friends'); // split for rendering
	$entry['friends_count'] = count($friends);

	// Build context
	$context = init_context($db);
	$context['entry'] = _prepare_entry_for_rendering($entry);

	// Render
	render('header', $context);
	render('details', $context);
	render('footer', $context);
}

function page_browse($db) {
	global $event_id;

	// Build query according to search params

	$not_coolness_search = false;
	$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM entry WHERE event_id = '$event_id'";
	if (isset($_GET['query']) && $_GET['query']) {
		$query = util_sanitize_query_param('query');
		$sql .= " AND (MATCH(author,title,platforms,type) 
			AGAINST ('$query' IN BOOLEAN MODE) OR uid = '$query')"; // WITH QUERY EXPANSION
		$empty_where = false;
		$not_coolness_search = true;
	}
	if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
		$sql .= " AND MATCH(platforms) AGAINST('";
		foreach ($_GET['platforms'] as $raw_platform) {
			$sql .= util_sanitize($raw_platform).' ';
		}
		$sql .= "')";
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
	//die($sql);

	// Fetch entries

	$entries = array();
	$results = mysqli_query($db, $sql) or log_error_and_die('Failed to fetch entries', mysqli_error($db)); 
	while ($row = mysqli_fetch_array($results)) {
		$entries[] = _prepare_entry_for_rendering($row);
	}
	$entry_count = db_select_single_value($db, 'SELECT FOUND_ROWS()');

	// Build context

	$context = init_context($db);
	$context['title'] = ($event_id == LDFF_ACTIVE_EVENT_ID && $not_coolness_search) ? 'Search results' : 'These entries need feedback!';
	$context['page'] = $page;
	$context['entries'] = $entries;
	if ($not_coolness_search) {
		$context['entry_count'] = $entry_count;
	}
	$context['entries_found'] = count($entries) > 0;
	$context['active_event'] = LDFF_ACTIVE_EVENT_ID;
	$context['search_event'] = $event_id;
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