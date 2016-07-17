<?php

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


// Rendering functions

function init_context($db) {
	global $events, $event_id;

	$events_render = array();
	foreach ($events as $id => $label) {
		$events_render[] = array(
			'id' => $id,
			'label' => $label
			);
	}

	$oldest_entry_updated = db_select_single_value($db, "SELECT last_updated FROM entry WHERE event_id = '$event_id' ORDER BY last_updated LIMIT 1");
	$oldest_entry_age = ceil((time() - strtotime($oldest_entry_updated)) / 60);

	$context = array();
	$context['event_title'] = isset($events[$event_id]) ? $events[$event_id] : 'Unknown event';
	$context['events'] = $events_render;
	$context['root'] = LDFF_ROOT_URL;
	$context['ld_root'] = LDFF_SCRAPING_ROOT . '/' . $event_id . '?action=preview&';
	$context['active_event'] = LDFF_ACTIVE_EVENT_ID;
	$context['search_event'] = $event_id;
	$context['is_active_event'] = LDFF_ACTIVE_EVENT_ID == $event_id;
	$context['oldest_entry_age'] = $oldest_entry_age;
	return $context;
}

function render($template_name, $context) {
	global $mustache;
	$template = $mustache->loadTemplate($template_name);
	$context['time'] = util_time_elapsed();
	return $template->render($context);
}

function prepare_entry_context($entry) {
	global $event_id;

	if (isset($entry['type'])) {
		$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
		$entry['type'] = util_format_type($entry['type']);
		$entry['platforms'] = util_format_platforms($entry['platforms']);
	}
	
	return $entry;
}

// PAGE : Entry details

function page_details_list_comments($db, $sql) {
	global $event_id;
	$results = mysqli_query($db, "$sql ORDER BY `date` DESC, `order` DESC")
		or log_error_and_die('Failed to fetch comments', mysqli_error($db)); 
	$comments = array();
	while ($comment = mysqli_fetch_array($results)) {
		/*if (!$comment['author']) { // Not an entry author for this event
			$comment['uid_author'] = null;
		}*/
		$comments[] = $comment;
	}
	return $comments;
}

function page_details($db) {
	global $event_id;
	
	// Disable in emergency mode
	if (LDFF_EMERGENCY_MODE) {
		page_static($db, 'emergency');
		return;
	}

	// Caching
	$uid = intval(util_sanitize_query_param('uid'));
	$cache_key = $event_id.'__uid-'.$uid;
	$output = cache_read($cache_key);

	// Force refresh
	if (isset($_GET['refresh'])) {
		util_require_admin();
		scraping_refresh_entry($db, $uid);
		$output = null;
	}

	if (!$output) {

		// Gather entry info
		$results = mysqli_query($db, "SELECT * FROM entry WHERE event_id = '$event_id' AND uid = ".$uid)
			or log_error_and_die('Failed to fetch entry', mysqli_error($db)); 
		$entry = mysqli_fetch_array($results);
		if (isset($entry['type'])) {
			$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
			$entry['given'] = page_details_list_comments($db,
				"SELECT comment.*, entry.author FROM comment, entry 
				WHERE comment.event_id = '$event_id' AND entry.event_id = '$event_id'
				AND comment.uid_entry = entry.uid
				AND comment.uid_author = $uid AND comment.uid_entry != $uid");
			$entry['received'] = page_details_list_comments($db,
				"SELECT * FROM comment WHERE event_id = '$event_id' 
				AND uid_entry = $uid AND uid_author != $uid 
				AND uid_author NOT IN(".(LDFF_UID_BLACKLIST?LDFF_UID_BLACKLIST:"\'\'").")");
			$entry['given_average'] = score_average($entry['given']);

			$results = mysqli_query($db, "SELECT DISTINCT(comment2.uid_author), entry.author FROM comment comment1, comment comment2, entry 
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
		}

		// Build context
		$context = init_context($db);
		$context['entry'] = prepare_entry_context($entry);

		// Render
		$output = render('header', $context)
			.render('details', $context)
			.render('footer', $context);

		cache_write($cache_key, $output);
	}

	echo $output;
}


// PAGE : Entries browsing & searching

function page_browse($db) {
	global $event_id;

	// Caching
	$uid = intval(util_sanitize_query_param('uid'));
	$output = null;
	$cache_key = null;
	if ((!isset($_GET['query']) || $_GET['query'] == '')
			&& (!isset($_GET['sorting']) || $_GET['sorting'] != 'random')) { // Don't cache text query-based searches
		$cache_key = $event_id.'__browse';
		if (LDFF_EMERGENCY_MODE) $cache_key .= '-emergency';
		if (isset($_GET['platforms'])) {
			$platforms = '';
			foreach ($_GET['platforms'] as $raw_platform) {
				$platforms .= util_sanitize($raw_platform).' ';
			}
			$cache_key .= '-platforms:'.$platforms;
		}
		if (isset($_GET['page'])) $cache_key .= '-page:'.util_sanitize_query_param('page');
		if (isset($_GET['ajax'])) $cache_key .= '-ajax:'.util_sanitize_query_param('ajax');
		if (isset($_GET['sorting'])) $cache_key .= '-sorting:'.util_sanitize_query_param('sorting');
	}
	$output = cache_read($cache_key);

	if (!$output) {

		// Build query according to search params
		$not_coolness_search = false;
		$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM entry WHERE event_id = '$event_id'";
		$sorting = 'coolness';
		if (!LDFF_EMERGENCY_MODE) {
			if (isset($_GET['query']) && $_GET['query']) {
				$query = util_sanitize_query_param('query');
				$sql .= " AND (MATCH(author,title,platforms,type) 
					AGAINST ('$query' IN BOOLEAN MODE) OR uid = '$query')";
				$empty_where = false;
				$not_coolness_search = true;
			}
			if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
				$sql .= " AND MATCH(platforms) AGAINST('";
				foreach ($_GET['platforms'] as $index => $raw_platform) {
					$sql .= util_sanitize($raw_platform).' ';
				}
				$sql .= "' IN BOOLEAN MODE)";
			}
			if (isset($_GET['sorting'])) {
				$sorting = util_sanitize_query_param('sorting');
			}
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
		$results = mysqli_query($db, $sql) or log_error_and_die('Failed to fetch entries', mysqli_error($db)); 
		while ($row = mysqli_fetch_array($results)) {
			$entries[] = prepare_entry_context($row);
		}
		$entry_count = db_select_single_value($db, 'SELECT FOUND_ROWS()');

		// Build context
		$context = init_context($db);
		$context['emergency_mode'] = LDFF_EMERGENCY_MODE;
		$context['title'] = ($event_id == LDFF_ACTIVE_EVENT_ID && $not_coolness_search) ? 'Search results' : 'These entries need feedback!';
		$context['page'] = $page;
		$context['entries'] = $entries;
		$context['entry_count'] = $entry_count;
		$context['are_entries_found'] = count($entries) > 0;
		$context['are_several_pages_found'] = $entry_count > LDFF_PAGE_SIZE;
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
			$output = render($template_name, $context);
		}
		else {
			$output = render('header', $context)
				.render('browse', $context)
				.render('footer', $context);
		}

		if ($cache_key) {
			cache_write($cache_key, $output);
		}

	}

	echo $output;
}


// PAGE : Simple, static pages

function page_static($db, $main_template) {
	$output = cache_read($main_template);
	if (!$output) {
		$context = init_context($db);
		$output = render('header', $context)
			.render($main_template, $context)
			.render('footer', $context);
		cache_write($cache_key, $output);
	}
	echo $output;
}


// Routing

if (isset($_GET['uid'])) {
	page_details($db);
}
else if (isset($_GET['p']) == 'faq') {
	page_static($db, 'faq');
}
else {
	page_browse($db);
}


mysqli_close($db);

?>
