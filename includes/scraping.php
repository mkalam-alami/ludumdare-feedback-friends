<?php 

// TODO Sanitize inputs better
function _escape($string) {
	return addslashes($string);
}

function _scraping_run_step_uids($db) {
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';

	$uids = http_fetch_uids();

	if (count($uids) > 0) {
		$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
		foreach ($uids as $uid) {
			$found = db_select_single_value($db, "SELECT COUNT(*) FROM entry WHERE uid = $uid");
			if ($found < 1 && strpos($missing_uids, $uid.',') === false) {
				$missing_uids .= $uid.',';
			}
		}
		setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
	}

	return $uids;
}

function _scraping_run_step_entry($db, $uid) {
	$entry = http_fetch_entry($uid); // TODO Fix encoding issues (e.g. LD35/UID 1645 author)

	// Save picture
	if ($entry['picture']) {
		util_check_picture_folder(LDFF_ACTIVE_EVENT_ID);
		$picture_data = file_get_contents($entry['picture']);
		file_put_contents(util_get_picture_path(LDFF_ACTIVE_EVENT_ID, $uid), $picture_data)
			or die('Cannot write in data/ folder');
	}

	// Save comments
	$max_order = db_select_single_value($db, "SELECT MAX(`order`) FROM `comment` WHERE uid_entry = '$uid'");
	$order = 1;
	$new_comments = false;
	$new_comments_sql = "INSERT IGNORE INTO `comment`(`event_id`,`uid_entry`,`order`,`uid_author`,`comment`,`date`,`score`) VALUES";
	foreach ($entry['comments'] as $comment) {
		if ($order++ > $max_order) {
			if ($new_comments) {
				$new_comments_sql .= ", ";
			}
			$new_comments = true;
			$new_comments_sql .= "('".LDFF_ACTIVE_EVENT_ID."',
					'$uid',
					'".$comment['order']."',
					'"._escape($comment['uid_author'])."',
					'"._escape($comment['comment'])."',
					'".date_format($comment['date'], 'Y-m-d H:i')."',
					'".score_evaluate_comment(_escape($comment['uid_author']), $uid, $comment['comment'])."'
					)";
		}
	}
	if ($new_comments) {
		mysqli_query($db, $new_comments_sql) or log_error(mysqli_error($db));
	}

	// Coolness
	$comments_given = score_comments_given($db, $uid);
	$comments_received = score_comments_received($db, $uid);
	$coolness = score_coolness($comments_given, $comments_received);

	// Update entry table
	mysqli_query($db, "UPDATE entry SET 
			author = '" . _escape($entry['author']) . "',
			title = '" . _escape($entry['title']) . "',
			type = '" . _escape($entry['type']) . "',
			description = '" . _escape($entry['description']) . "',
			platforms = '" . _escape($entry['platforms']) . "',
			comments_given = '$comments_given',
			comments_received = '$comments_received',
			coolness = '$coolness',
			last_updated = CURRENT_TIMESTAMP()
			WHERE uid = '$uid' AND event_id = '" . LDFF_ACTIVE_EVENT_ID . "'");
	if (mysqli_affected_rows($db) == 0) {
		mysqli_query($db, "INSERT INTO 
			entry(uid,event_id,author,title,type,description,platforms,comments_given,comments_received,coolness,last_updated) 
			VALUES('$uid',
				'" . LDFF_ACTIVE_EVENT_ID . "',
				'" . _escape($entry['author']). "',
				'" . _escape($entry['title']). "',
				'" . _escape($entry['type']). "',
				'" . _escape($entry['description']). "',
				'" . _escape($entry['platforms']). "',
				'$comments_given',
				'$comments_received',
				'$coolness',
				CURRENT_TIMESTAMP()
				)");
	}

	return $entry;
}

function scraping_refresh_entry($db, $uid) {
	_scraping_run_step_entry($db, $uid);
}

function _scraping_log_step($report_entry) {
	$log_entry = "Scraping step:".
			" type = ".$report_entry['type'].
			" duration = ".$report_entry['duration'];

	if ($report_entry['type'] == 'uids') {
		$log_entry .= " uids = " . count($report_entry['result']);
	}	
	else if ($report_entry['type'] == 'entry') {
		$result = $report_entry['result'];
		$log_entry .= ' params = '.$report_entry['params'].
			' result = '.$result['title'].' by '.$result['author'].
			', with '.count($result['comments']).' comments';
	}

	if ($report_entry['error']) {
		log_error('Error for step '.
			$report_entry['type'].' '.$report_entry['params'].': '.
			$report_entry['error']);
	}
	else {
		log_info($log_entry);
	}
}

function _scraping_log_report($report) {
	$summary = '';
	foreach ($report['steps'] as $step) {
		$summary .= $step['type'];
		if (isset($step['params'])) {
			$summary .= ':'.$step['params'];
		}
		$summary .= ' ';
	}
	log_info("Scraping report:".
		" duration = ".$report['total_duration'].
		" step_count = ".$report['step_count'].
		" average_step_duration = ".$report['average_step_duration'].
		" slept_per_step = ".$report['slept_per_step'].
		" timeout = ".$report['timeout'].
		" summary = $summary");
}

/*
	How it works:
		- Scraping is cut in small "steps" to better control execution time.
		  Every step, we either:
			- Read the UIDs page
			- Fetch info for a entry

		1. Read the UIDs page and store all the UIDs missing from the DB in the "setting" table
		2. If we found missing UIDs, then run through them to scrape them. Otherwise, run through all existing UIDs.
		3. Back to 1

*/
function scraping_run($db) {
	static $SETTING_EVENT_ID = 'scraping_event_id';
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';
	static $SETTING_LAST_READ_ENTRY = 'scraping_last_read_entry';

	// Init
	$report = array();
	$report['steps'] = array();
	$start_time = microtime(true);
	$last_step_time = $start_time;
	$steps = 0;
	$average_step_duration = 0;
	$over = false;

	// Reset scraping on event ID change
	$event_id_cache = setting_read($db, $SETTING_EVENT_ID, -1);
	if ($event_id_cache != LDFF_ACTIVE_EVENT_ID) {
		setting_write($db, $SETTING_MISSING_UIDS, '');
		setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
		setting_write($db, $SETTING_EVENT_ID, LDFF_ACTIVE_EVENT_ID);
	}

	// Loop until we're about to reach the timeout
	while (!$over) {
		$over = $last_step_time - $start_time + $average_step_duration > LDFF_SCRAPING_TIMEOUT;

		$report_entry = array();

		// Read UIDs page
		$last_read_entry = setting_read($db, $SETTING_LAST_READ_ENTRY, -1);
		if ($last_read_entry == -1) {
			$uids = _scraping_run_step_uids($db);
			$report_entry['type'] = 'uids';
			$report_entry['result'] = $uids;
			$report_entry['error'] = mysqli_error($db);
			setting_write($db, $SETTING_LAST_READ_ENTRY, 0);
		}

		// Fetch entry info
		else {
			$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
			$next_uid = null;
			
			// Go through missing UIDs, OR through all existing entries
			$fetching_missing_uid = false;
			if (strlen($missing_uids) > 0) {
				$missing_uids_array = explode(',', $missing_uids, 2);
				$uid = $missing_uids_array[0];
				if ($uid != '') {
					$fetching_missing_uid = true;
					$missing_uids = $missing_uids_array[1];
				}
			}
			if (!$fetching_missing_uid) {
				$results = mysqli_query($db, "SELECT uid FROM entry WHERE uid > '$last_read_entry' 
					AND event_id = '".LDFF_ACTIVE_EVENT_ID."' LIMIT 2");
				if (mysqli_num_rows($results) > 0) {
					$data = mysqli_fetch_array($results);
					$uid = $data['uid'];
					$data = mysqli_fetch_array($results);
					$next_uid = $data['uid'];
				}
			}

			if ($uid != null) {
				$entry = _scraping_run_step_entry($db, $uid);
				$report_entry['type'] = 'entry';
				$report_entry['params'] = $uid . ',' . ($fetching_missing_uid?'insert':'update');
				$report_entry['result'] = $entry;
				$report_entry['error'] = mysqli_error($db);

				if ($fetching_missing_uid) {
					setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
					if ($missing_uids == '') {
						setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
					}
				}
				else {
					setting_write($db, $SETTING_LAST_READ_ENTRY, $uid);
					if ($next_uid == null) {
						setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
					}
				}
			}
			else {
				log_warning("No UID to scrape found, forcing scraping reset");
				setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
			}
		}
	
		if (!$over && !LDFF_SCRAPING_PSEUDO_CRON) {
			usleep(LDFF_SCRAPING_SLEEP * 1000000);
		}

		$time = microtime(true);
		$report_entry['duration'] = round($time - $last_step_time, 3);
		$report['steps'][] = $report_entry;
		_scraping_log_step($report_entry);
		$last_step_time = $time;
		$steps++;
		$average_step_duration = ($last_step_time - $start_time) / $steps;
	}

	$report['step_count'] = $steps;
	$report['average_step_duration'] = round($average_step_duration);
	$report['total_duration'] = round($last_step_time - $start_time, 3);
	$report['slept_per_step'] = LDFF_SCRAPING_SLEEP;
	$report['timeout'] = LDFF_SCRAPING_TIMEOUT;
	_scraping_log_report($report);

	// TODO Format and log report in file
	return $report;
}


?>