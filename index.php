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

$event_id = LDFF_ACTIVE_EVENT_ID;
if (isset($_GET['event']) && $_GET['event']) {
	$event_id = util_sanitize_query_param('event');
}

// Rendering functions

function init_context($db) {
	global $events, $event_id;

	// Prepare config (will be available in JS in the "config" global variable)
	$config = array(
		array('key' => 'LDFF_ACTIVE_EVENT_ID', 'value' => LDFF_ACTIVE_EVENT_ID),
		array('key' => 'LDFF_ROOT_URL', 'value' => LDFF_ROOT_URL),
		array('key' => 'LDFF_SCRAPING_ROOT', 'value' => LDFF_SCRAPING_ROOT),
	);

	// Prepare events list
	$events_render = array();
	foreach ($events as $id => $label) {
		$events_render[] = array(
			'id' => $id,
			'label' => $label
			);
	}

 	// Prepare oldest entry age
	$rows = db_query($db, "SELECT last_updated FROM entry WHERE event_id = ? ORDER BY last_updated LIMIT 1", 's', $event_id);
	if ($rows && count($rows) == 1) {
		$oldest_entry_updated = $rows[0]['last_updated'];
		$oldest_entry_age = ceil((time() - strtotime($oldest_entry_updated)) / 60);
	}
	else {
		$oldest_entry_age = '?';
	}

	$context = array();
	$context['config'] = $config;
	$context['root'] = LDFF_ROOT_URL; // TODO Remove, use config instead
	$context['ld_root'] = LDFF_SCRAPING_ROOT; // TODO Remove, use config instead
	$context['active_event'] = LDFF_ACTIVE_EVENT_ID; // TODO Remove, use config instead
	$context['emergency_mode'] = LDFF_EMERGENCY_MODE;
	$context['event_title'] = isset($events[$event_id]) ? $events[$event_id] : 'Unknown event';
	$context['event_url'] = LDFF_SCRAPING_ROOT . $event_id . '/?action=preview';
	$context['events'] = $events_render;
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
		$rows = db_query($db, "SELECT * FROM entry WHERE event_id = ? AND uid = ? LIMIT 1", 'si', $event_id, $uid);
		if (!$rows) {
			log_error_and_die('Failed to fetch entry', mysqli_error($db));
		}
		$entry = count($rows) == 1 ? $rows[0] : [];

		if (isset($entry['type'])) {
			// Rendering info
			$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
			$entry['type_label'] = util_format_type($entry['type']);
			$entry['platforms_label'] = util_format_platforms($entry['platforms']);

			// Comments (given)
			$entry['given'] = db_query($db, "SELECT comment.*, entry.author FROM comment, entry
				WHERE comment.event_id = ? AND entry.event_id = ?
				AND comment.uid_entry = entry.uid
				AND comment.uid_author = ? AND comment.uid_entry != ?
				ORDER BY `date` DESC, `order` DESC",
				'ssii', $event_id, $event_id, $uid, $uid);

			// Comments (received)
			$entry['received'] = db_query($db, "SELECT * FROM comment WHERE event_id = ?
				AND uid_entry = ? AND uid_author != ?
				AND uid_author NOT IN(".(LDFF_UID_BLACKLIST?LDFF_UID_BLACKLIST:"''").")
				ORDER BY `date` DESC, `order` DESC", 
				'sii', $event_id, $uid, $uid);

			// Mentions
			$entry_author = "%@{$entry['author']}%";
			$entry['mentions'] = db_query($db, "SELECT * FROM comment WHERE event_id = ?
				AND comment LIKE ?
				ORDER BY `date` DESC, `order` DESC",
				'ss', $event_id, $entry_author);

			// Highlight mentions in bold
			foreach ($entry['mentions'] as &$mention) {
				$mention['comment'] = str_replace('@'.$entry['author'], '<b>@'.$entry['author'].'</b>', $mention['comment']);
			};

			// Friends
			$friends = db_query($db, "SELECT DISTINCT(comment2.uid_author), entry.author FROM comment comment1, comment comment2, entry
					WHERE comment1.event_id = ?
					AND comment2.event_id = ?
					AND comment1.uid_author = ?
					AND comment2.uid_author != ?
					AND comment1.uid_entry = comment2.uid_author
					AND comment2.uid_entry = ?
					AND entry.uid = comment2.uid_author ORDER BY comment1.date DESC",
					'ssiii', $event_id, $event_id, $uid, $uid, $uid);
			$entry['friends_rows'] = util_array_chuck_into_object($friends, 5, 'friends'); // transformed for rendering

			// Misc stats
			$entry['given_average'] = score_average($entry['given']);
			$entry['given_count'] = count($entry['given']);
			$entry['received_count'] = count($entry['received']);
			$entry['friends_count'] = count($friends);
		}

		// Build context
		$context = init_context($db);
		$context['entry'] = $entry;

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
	$output = null;
	$cache_key = $event_id.'__browse';
	$output = cache_read($cache_key);

	if (!$output) {

		// Build context
		$templates = util_load_templates(['results', 'result', 'cartridge']);

		$context = init_context($db);
		$context['templates'] = $templates;

		// Render
		$output = render('header', $context)
			.render('browse', $context)
			.render('footer', $context);

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
