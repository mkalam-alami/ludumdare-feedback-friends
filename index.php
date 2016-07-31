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

	$stmt = mysqli_prepare($db, "SELECT last_updated FROM entry WHERE event_id = ? ORDER BY last_updated LIMIT 1");
	mysqli_stmt_bind_param($stmt, 's', $event_id);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row = mysqli_fetch_array($result);
	$oldest_entry_updated = $row[0];
	mysqli_stmt_close($stmt);
	$oldest_entry_age = ceil((time() - strtotime($oldest_entry_updated)) / 60);

	$context = array();
	$context['root'] = LDFF_ROOT_URL;
	$context['ld_root'] = LDFF_SCRAPING_ROOT;
	$context['event_title'] = isset($events[$event_id]) ? $events[$event_id] : 'Unknown event';
	$context['event_url'] = LDFF_SCRAPING_ROOT . $event_id . '/?action=preview';
	$context['events'] = $events_render;
	$context['active_event'] = LDFF_ACTIVE_EVENT_ID;
	$context['search_event'] = $event_id;
	$context['is_active_event'] = LDFF_ACTIVE_EVENT_ID == $event_id;
	$context['oldest_entry_age'] = $oldest_entry_age;
	$context['google_analytics_id'] = LDFF_GOOGLE_ANALYTICS_ID;

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
		$entry_stmt = mysqli_prepare($db, "SELECT * FROM entry WHERE event_id = ? AND uid = ?");
		mysqli_stmt_bind_param($entry_stmt, 'si', $event_id, $uid);
		if(!mysqli_stmt_execute($entry_stmt)){
			mysqli_stmt_close($entry_stmt);
			log_error_and_die('Failed to fetch entry', mysqli_error($db));
		}
		$results = mysqli_stmt_get_result($entry_stmt);
		$entry = mysqli_fetch_array($results);
		mysqli_stmt_close($entry_stmt);

		if (isset($entry['type'])) {
			$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);

			// Comments
			$comments_given_stmt = mysqli_prepare($db, "SELECT comment.*, entry.author FROM comment, entry
				WHERE comment.event_id = ? AND entry.event_id = ?
				AND comment.uid_entry = entry.uid
				AND comment.uid_author = ? AND comment.uid_entry != ?
				ORDER BY `date` DESC, `order` DESC");

			mysqli_stmt_bind_param($comments_given_stmt, 'ssii', $event_id, $event_id, $uid, $uid);
			if(!mysqli_stmt_execute($comments_given_stmt)){
				mysqli_stmt_close($comments_given_stmt);
				log_error_and_die('Failed to fetch entry', mysqli_error($db));
			}
			$results = mysqli_stmt_get_result($comments_given_stmt);
			$comments = array();
			while ($comment = mysqli_fetch_array($results)) {
				$comments[] = $comment;
			}
			$entry['given'] = $comments;
			mysqli_stmt_close($comments_given_stmt);

			$comments_received_stmt = mysqli_prepare($db, "SELECT * FROM comment WHERE event_id = ?
				AND uid_entry = ? AND uid_author != ?
				AND uid_author NOT IN(".(LDFF_UID_BLACKLIST?LDFF_UID_BLACKLIST:"''").")
				ORDER BY `date` DESC, `order` DESC");

			mysqli_stmt_bind_param($comments_received_stmt, 'sii', $event_id, $uid, $uid);
			if(!mysqli_stmt_execute($comments_received_stmt)){
				mysqli_stmt_close($comments_received_stmt);
				log_error_and_die('Failed to fetch entry', mysqli_error($db));
			}
			$results = mysqli_stmt_get_result($comments_received_stmt);
			$comments = array();
			while ($comment = mysqli_fetch_array($results)) {
				$comments[] = $comment;
			}
			$entry['received'] = $comments;
			mysqli_stmt_close($comments_received_stmt);

			$mentions_stmt = mysqli_prepare($db, "SELECT * FROM comment WHERE event_id = ?
				AND comment LIKE ?
				ORDER BY `date` DESC, `order` DESC");
			$entry_author = "%@{$entry['author']}%";
			mysqli_stmt_bind_param($mentions_stmt, 'ss', $event_id, $entry_author);
			if(!mysqli_stmt_execute($mentions_stmt)){
				mysqli_stmt_close($mentions_stmt);
				log_error_and_die('Failed to fetch entry', mysqli_error($db));
			}
			$results = mysqli_stmt_get_result($mentions_stmt);
			$comments = array();
			while ($comment = mysqli_fetch_array($results)) {
				$comments[] = $comment;
			}


			$entry['mentions'] = $comments;
			mysqli_stmt_close($mentions_stmt);

			// Highlight mentions in bold
			foreach ($entry['mentions'] as &$mention) {
				$mention['comment'] = str_replace('@'.$entry['author'], '<b>@'.$entry['author'].'</b>', $mention['comment']);
			};

			// Friends
			$friends_stmt = mysqli_prepare($db, "SELECT DISTINCT(comment2.uid_author), entry.author FROM comment comment1, comment comment2, entry
					WHERE comment1.event_id = ?
					AND comment2.event_id = ?
					AND comment1.uid_author = ?
					AND comment2.uid_author != ?
					AND comment1.uid_entry = comment2.uid_author
					AND comment2.uid_entry = ?
					AND entry.uid = comment2.uid_author ORDER BY comment1.date DESC");

			mysqli_stmt_bind_param($friends_stmt, 'ssiii',
				$event_id,
				$event_id,
				$uid,
				$uid,
				$uid
			);

			if(!mysqli_stmt_execute($friends_stmt)){
				mysqli_stmt_close($friends_stmt);
				log_error_and_die('Failed to fetch comments', mysqli_error($db));
			}
			$results = mysqli_stmt_get_result($friends_stmt);

			$friends = array();
			while ($friend = mysqli_fetch_array($results)) {
				$friends[] = $friend;
			}
			$entry['friends_rows'] = util_array_chuck_into_object($friends, 5, 'friends'); // transformed for rendering

			mysqli_stmt_close($friends_stmt);

			// Misc numbers
			$entry['given_average'] = score_average($entry['given']);
			$entry['given_count'] = count($entry['given']);
			$entry['received_count'] = count($entry['received']);
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

	// Determine user ID and store in the cookie; GET param overrides any old cookie
	$userid = null;
	if (isset($_GET['userid'])) {
		$userid = util_sanitize_query_param('userid');
	} else if (isset($_COOKIE['userid'])) {
		$userid = util_sanitize($_COOKIE['userid']);
	}
	if (!is_numeric($userid)) {
		$userid = null;
	}
	if ($userid) {
		setcookie('userid', $userid, time() + 31*24*60*60);
	} else {
		// Clear the cookie by setting an expiry time in the past
		setcookie('userid', '', time() - 60*60);
	}

	// Caching
	$uid = intval(util_sanitize_query_param('uid'));
	$output = null;
	$cache_key = null;
	if ((!isset($_GET['query']) || $_GET['query'] == '') // Don't cache text searches
			&& (!isset($_GET['sorting']) || $_GET['sorting'] != 'random')) { // Don't cache randomized pages
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
		if (isset($_GET['userid'])) $cache_key .= '-userid:'.util_sanitize_query_param('userid');
	}
	$output = cache_read($cache_key);

	if (!$output) {

		// Fetch corresponding username
		$username = null;
		if ($userid) {
			$stmt = mysqli_prepare($db, "SELECT author FROM entry WHERE uid = ? LIMIT 1");
			mysqli_stmt_bind_param($stmt, 'i', $userid);
			if(!mysqli_stmt_execute($stmt)){
				mysqli_stmt_close($stmt);
				log_error_and_die('Failed to fetch username', mysqli_error($db));
			}
			$results = mysqli_stmt_get_result($stmt);
			if ($row = mysqli_fetch_array($results)) {
				$username = $row[0];
			}
			mysqli_stmt_close($stmt);
		}

		// Build query according to search params
		// For the structure of the join, see http://sqlfiddle.com/#!9/26ae79/8.
		$not_coolness_search = false;
		$have_search_query = isset($_GET['query']) && !!$_GET['query'];
		$filter_by_user = !LDFF_EMERGENCY_MODE && !!$userid && !$have_search_query;
		$bind_params_str = '';
		$bind_params = array();
		$sql = "SELECT SQL_CALC_FOUND_ROWS entry.*";
		if ($filter_by_user) {
			// Count comments by entry (see also the GROUP BY later on)
			$sql .= ", COUNT(comment.uid_entry) AS comments_by_current_user";
		}
		$sql .= " FROM entry";
		if ($filter_by_user) {
			// Join on the comments table, selecting only the comments authored by the current user
			$sql .= " LEFT JOIN comment";
			$sql .= "    ON entry.uid = comment.uid_entry";
			$sql .= "   AND entry.event_id = comment.event_id";
			$sql .= "   AND comment.uid_author = ?";
			$bind_params_str .= 'i';
			$bind_params[] = $userid;
		}
		$sql .= " WHERE entry.event_id = ?";
		$bind_params_str .= 'i';
		$bind_params[] = $event_id;

		if ($filter_by_user) {
			// Omit the current user's own game
			$sql .= " AND entry.uid != ?";
			$bind_params_str .= 'i';
			$bind_params[] = $userid;
		}
		$sorting = 'coolness';
		if (!LDFF_EMERGENCY_MODE) {
			if ($have_search_query) {
				$query = util_sanitize_query_param('query');
				$sql .= " AND (MATCH(entry.author,entry.title,entry.platforms,entry.type)
					AGAINST (? IN BOOLEAN MODE) OR entry.uid = ?)";
				$bind_params_str .= 'si';
				$bind_params[] = $query;
				$bind_params[] = $query;

				$empty_where = false;
				$not_coolness_search = true;
			}
			if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
				$platforms_match = '';
				foreach ($_GET['platforms'] as $index => $raw_platform) {
					$platforms_match .= util_sanitize($raw_platform).' ';
				}
				$sql .= " AND MATCH(entry.platforms) AGAINST(? IN BOOLEAN MODE)";
				$bind_params_str .= 's';
				$bind_params[] = $platforms_match;
			}
			if (isset($_GET['sorting'])) {
				$sorting = util_sanitize_query_param('sorting');
			}
		}
		if ($filter_by_user) {
			// Group by entry, then select only those entries that received 0 comments from the current user
			$sql .= " GROUP BY entry.uid, entry.event_id";
			$sql .= " HAVING comments_by_current_user = 0";
		}
		switch ($sorting) {
			case 'random': $sql .= " ORDER BY RAND()"; break;
			case 'received': $sql .= " ORDER BY entry.comments_received, entry.comments_given DESC, entry.last_updated DESC"; break;
			case 'received_desc': $sql .= " ORDER BY entry.comments_received DESC, entry.last_updated DESC"; break; // Hidden, not indexed
			case 'given': $sql .= " ORDER BY entry.comments_given DESC, entry.last_updated DESC"; break; // Hidden, not indexed
			case 'laziest': $sql .= " ORDER BY entry.coolness, entry.comments_given, entry.comments_received, entry.last_updated DESC"; break; // Hidden, not indexed
			default: $sql .= " ORDER BY entry.coolness DESC, entry.last_updated DESC";
		}
		$sql .= " LIMIT ".LDFF_PAGE_SIZE;
		$page = 1;
		if (isset($_GET['page'])) {
			$page = intval(util_sanitize_query_param('page'));
			$sql .= " OFFSET " . (($page - 1) * LDFF_PAGE_SIZE);
		}

		// Fetch entries

		$stmt = mysqli_prepare($db, $sql);
		$params = array();
		$n = count($bind_params);
		for($i=0; $i < $n; ++$i){
			$id = "param$i";
			$$id = $bind_params[$i];
			$params[] = &$$id;
		}
		// Uncomment to explain query plan
		//db_explain_query($db, $sql, $bind_params_str, $params);
		mysqli_stmt_bind_param($stmt, $bind_params_str, ...$params);
		$entries = array();
		if(!mysqli_stmt_execute($stmt)){
			mysqli_stmt_close($stmt);
			log_error_and_die('Failed to fetch entries', mysqli_error($db));
		}
		$results = mysqli_stmt_get_result($stmt);
		while ($row = mysqli_fetch_array($results)) {
			$entries[] = prepare_entry_context($row);
		}
		mysqli_stmt_close($stmt);

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
		$context['username'] = $username;
		$context['userid'] = $userid;
		$context['search_query'] = util_sanitize_query_param('query');
		$context['search_sorting'] = $sorting;
		if (isset($_GET['platforms']) && is_array($_GET['platforms'])) {
			$context['search_platforms'] = implode(', ', array_map('util_sanitize', $_GET['platforms']));
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
		cache_write($main_template, $output);
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
