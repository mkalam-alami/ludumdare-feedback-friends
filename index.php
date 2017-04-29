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
		array('key' => 'LD_WEB_ROOT', 'value' => LD_WEB_ROOT),
		array('key' => 'LD_OLD_WEB_ROOT', 'value' => LD_OLD_WEB_ROOT),
		array('key' => 'LD_SCRAPING_ROOT', 'value' => LD_SCRAPING_ROOT)
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
	} else {
		$oldest_entry_age = '?';
	}

	$context = array();
	$context['config'] = $config;
	$context['root'] = LDFF_ROOT_URL;
	$context['ld_root'] = LD_WEB_ROOT;
	$context['emergency_mode'] = LDFF_EMERGENCY_MODE;
	$context['event'] = $event_id;
	$context['event_title'] = isset($events[$event_id]) ? $events[$event_id] : 'Unknown event';
	$context['event_url'] = (is_numeric($event_id)) ? LD_WEB_ROOT : (LD_OLD_WEB_ROOT . $event_id . '/?action=preview'); // TODO Better ldjam.com event URL
	$context['events'] = $events_render;
	$context['oldest_entry_age'] = $oldest_entry_age;
	$context['google_analytics_id'] = LDFF_GOOGLE_ANALYTICS_ID;
	$context['js_css_version'] = LDFF_JS_CSS_VERSION;
	return $context;
}

function render($template_name, $context) {
	global $mustache;
	$template = $mustache->loadTemplate($template_name);
	$context['time'] = util_time_elapsed();
	return $template->render($context);
}

// PAGE : Entry details

function _fetch_entry($db, $event_id, $uid) {
	// Accept either entry UID or author UID, otherwise links to commenter entries would be costly
	$rows = db_query($db, "SELECT * FROM entry WHERE event_id = ? AND (uid = ? OR uid_author = ?) LIMIT 1", 'sii', $event_id, $uid, $uid);
	if ($rows === false) {
		log_error_and_die('Failed to fetch entry', mysqli_error($db));
	}
	return count($rows) == 1 ? $rows[0] : [];
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
	$entry = null;
	if (isset($_GET['refresh'])) {
		$entry = _fetch_entry($db, $event_id, $uid);
		if (time() - strtotime($entry['last_updated']) > LDFF_FORCE_REFRESH_DELAY || util_is_admin()) {
			scraping_refresh_entry($db, $event_id, $entry['uid']);
			$entry = null;
			$output = null;
		}
	}

	if (!$output) {
		// Gather entry info
		if ($entry == null) {
			$entry = _fetch_entry($db, $event_id, $uid);
		}

		if (isset($entry['type'])) {
			// Wordpress-compatible author UID
			$uid_author = $entry['uid_author'];
			if ($uid_author == 0) {
				$uid_author = $entry['uid'];
			}

			// Rendering info
			$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
			$entry['type_label'] = util_format_type($entry['type']);
			$entry['platforms_label'] = util_format_platforms($entry['platforms']);

			// Comments (given)
			$entry['given'] = db_query($db, "SELECT comment.*, entry.author FROM comment, entry
				WHERE comment.event_id = ? AND entry.event_id = ?
				AND comment.uid_entry = entry.uid
				AND comment.uid_entry != ? AND comment.uid_author = ?
				ORDER BY `date` DESC, `order` DESC",
				'ssii', $event_id, $event_id, $entry['uid'], $uid_author);

			// Comments (received)
			$entry['received'] = db_query($db, "SELECT * FROM comment WHERE event_id = ?
				AND uid_entry = ? AND uid_author != ?
				AND uid_author NOT IN(".(LDFF_UID_BLACKLIST?LDFF_UID_BLACKLIST:"''").")
				ORDER BY `date` DESC, `order` DESC", 
				'sii', $event_id, $entry['uid'], $uid_author);

			// Mentions
			$entry_author = "%@{$entry['author']}%";
			$entry['mentions'] = db_query($db, "SELECT * FROM comment WHERE event_id = ?
				AND comment LIKE ?
				ORDER BY `date` DESC, `order` DESC",
				'ss', $event_id, $entry_author);

			// Highlight mentions in bold
			foreach ($entry['mentions'] as &$mention) {
				$mention['comment'] = preg_replace('/\@'.$entry['author'].'/i', '<b>@'.$entry['author'].'</b>', $mention['comment']);
			};

			// Friends
			$friends = db_query($db, "SELECT DISTINCT(comment2.uid_author), comment2.author FROM comment comment1, comment comment2, entry entry2
					WHERE comment1.event_id = ?
					AND comment1.uid_author = ?
					AND comment1.uid_entry = entry2.uid
					AND comment2.event_id = comment1.event_id
					AND comment2.uid_author != comment1.uid_author
					AND comment2.uid_entry = ?
					AND entry2.event_id = comment2.event_id
					AND (entry2.uid_author = comment2.uid_author OR (entry2.uid_author = 0 AND entry2.uid = comment2.uid_author))
					ORDER BY comment1.date DESC",
					'sii', $event_id, $uid_author, $entry['uid']);
			$entry['friends_rows'] = util_array_chuck_into_object($friends, 5, 'friends'); // transformed for rendering

			// Misc stats
			$entry['given_average'] = score_average($entry['given']);
			$entry['given_count'] = count($entry['given']);
			$entry['received_count'] = count($entry['received']);
			$entry['friends_count'] = count($friends);
		}

		// Build context
    if ($entry) {
			$last_updated_secs = time() - strtotime($entry['last_updated']);
			$context = init_context($db);
			if ($entry && $last_updated_secs < LDFF_FORCE_REFRESH_DELAY) {
				$context['refresh_disabled'] = 'disabled';
			}
	    if ($last_updated_secs < 60*60*48) {
				$last_updated_mns = round($last_updated_secs/60);
				$entry['last_updated'] = $last_updated_mns . ' minute' . (($last_updated_mns!=1)?'s':'') . ' ago';
	    }
			if ($entry['entry_page']) {
				$entry['entry_url'] = LD_WEB_ROOT . $entry['entry_page'];
				$entry['author_url'] = LD_WEB_ROOT . '/users/' . $entry['author_page'];
			} else {
				$entry['entry_url'] = LD_OLD_WEB_ROOT . $event_id . '/?action=preview&uid=' . $entry['uid'];
				if ($entry['author_page']) {
					$entry['author_url'] = LD_OLD_WEB_ROOT . 'author/' . $entry['author_page'];
				}
			}
		}
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
} else if (isset($_GET['p']) && array_search($_GET['p'], array('help', 'about')) !== false) {
	page_static($db, $_GET['p']);
} else {
	page_browse($db);
}


mysqli_close($db);

?>
