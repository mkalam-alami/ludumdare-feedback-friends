<?php

function _scraping_is_uid_blacklisted($uid) {
	static $blacklist = null;
	if (!$blacklist) {
		$blacklist = explode(',', LDFF_UID_BLACKLIST);
	}

	return array_search($uid, $blacklist) !== false;
}

function _scraping_run_step_uids($db, $page) {
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';
	global $db;
	
	// Find a page of entry UIDs and mark missing ones
	$event_id = LDFF_ACTIVE_EVENT_ID;
	$uids = ld_fetch_uids($event_id, $page);
	$stmt = mysqli_prepare($db, "SELECT COUNT(*) FROM entry WHERE uid = ? AND event_id = ?");
	if (count($uids) > 0) {
		$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
		foreach ($uids as $uid) {
			mysqli_stmt_bind_param($stmt, 'is', $uid, $event_id);
			mysqli_stmt_execute($stmt);
			$result = mysqli_stmt_get_result($stmt);
			$row = mysqli_fetch_array($result);
			$found = $row[0];
			if ($found < 1 && strpos($missing_uids, $uid.',') === false) {
				$missing_uids .= $uid.',';
			}
		}
		setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
	}
	mysqli_stmt_close($stmt);

	return $uids;
}

function _scraping_run_step_entry($db, $event_id, $uid, $author_cache = [], $ignore_write_errors = false) {
	$entry = null;

	if (!_scraping_is_uid_blacklisted($uid)) {
		$uid_author = db_select_single_value($db, "SELECT uid_author FROM entry WHERE event_id = ? AND uid = ?",
				'si', $event_id, $uid);
		$entry = ld_fetch_entry($event_id, $uid, $uid_author, $author_cache);
	}	else {
		$stmt = mysqli_prepare($db, "DELETE FROM entry WHERE uid = ?");
		mysqli_stmt_bind_param($stmt, 'i', $uid);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
	}

	if ($entry) {

		// Save picture
		if ($entry['picture']) {
			util_check_picture_folder($event_id);
			$picture_data = file_get_contents($entry['picture']);
			$temp_path = tempnam(__DIR__ . '/../data', 'tmppic');
      $picture_path = util_get_picture_file_path($event_id, $uid);
			if (!file_put_contents($temp_path, $picture_data) && !$ignore_write_errors) {
				$error_info = error_get_last();
				log_warning('Failed to download picture for entry ' . $entry['title'] . ': ' . $error_info['message']);
			} else {
				util_resize_image($temp_path, $picture_path, 320);
				unlink($temp_path);
			}
		}
        
    // Clear comments if a refresh is needed
    if (LDFF_SCRAPING_REFRESH_COMMENTS) {
        db_query($db, "DELETE FROM `comment` WHERE uid_entry = ? AND event_id = ?",
            'is', $uid, $event_id);
    }

		// Save comments
		$max_order = db_select_single_value($db,
			"SELECT MAX(`order`) FROM `comment` WHERE uid_entry = ? AND event_id = ?", 
			'is', $uid, $event_id);
		$order = 1;
		$new_comments = false;

		$stmt = mysqli_prepare($db, "INSERT IGNORE INTO `comment`(`event_id`,`uid_entry`,`order`, `uid_author`,`author`,`comment`,`date`,`score`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

		$score_per_author = array();
		foreach ($entry['comments'] as $comment) {
			$uid_author = $comment['uid_author'];
			if (!isset($score_per_author[$uid_author])) {
				$score_per_author[$uid_author] = 0;
			}
			$score = score_evaluate_comment($uid_author,
				$entry['uid_author'],
				$comment['comment'],
				$score_per_author[$uid_author]);
			$score_per_author[$uid_author] += $score;

			if ($order++ > $max_order) {
				mysqli_stmt_bind_param($stmt,
					'siissssi',
				 	$event_id,
					$uid,
					$comment['order'],
					$comment['uid_author'],
					$comment['author'],
					$comment['comment'],
					date_format($comment['date'], 'Y-m-d H:i'),
					$score
				);
				mysqli_stmt_execute($stmt) or log_error(mysqli_error($db));
			}
		}

		mysqli_stmt_close($stmt);

		// Coolness
		$comments_given = score_comments_given($db, $event_id, $uid, $entry['uid_author']);
		$comments_received = score_comments_received($db, $event_id, $uid, $entry['uid_author']);
		$coolness = score_coolness($comments_given, $comments_received);

		// Update entry table
		$update_stmt = mysqli_prepare($db, "UPDATE entry SET uid_author=?, author=?, author_page=?, entry_page=?, title=?, type=?, description=?, platforms=?, comments_given=?, comments_received=?, coolness=?, last_updated=CURRENT_TIMESTAMP() WHERE uid=? and event_id=?");
		mysqli_stmt_bind_param($update_stmt,
			'isssssssiiiis',
			$entry['uid_author'],
			$entry['author'],
			$entry['author_page'],
			$entry['entry_page'],
			$entry['title'],
			$entry['type'],
			$entry['description'],
			$entry['platforms'],
			$comments_given,
			$comments_received,
			$coolness,
			$uid,
			$event_id
		);
		mysqli_stmt_execute($update_stmt);

		if (mysqli_stmt_affected_rows($update_stmt) == 0) {
			db_query($db, "INSERT INTO
				entry(uid,uid_author,event_id,author,author_page,entry_page,title,type,description,platforms,
					comments_given,comments_received,coolness,last_updated)
				VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP())",
				'iissssssssiii',
				$uid,
				$entry['uid_author'],
				$event_id,
				$entry['author'],
				$entry['author_page'],
				$entry['entry_page'],
				$entry['title'],
				$entry['type'],
				$entry['description'],
				$entry['platforms'],
				$comments_given,
				$comments_received,
				$coolness);
		}
		mysqli_stmt_close($update_stmt);
	}

	return $entry;
}

function scraping_refresh_entry($db, $event_id, $uid) {
	_scraping_run_step_entry($db, $event_id, $uid, [], true);
}

function _scraping_log_step($report_entry) {
	$log_entry = "Scraping step:".
	" type = ".$report_entry['type'].
	" duration = ".$report_entry['duration'];

	if ($report_entry['type'] == 'uids') {
		$log_entry .= " uids = " . count($report_entry['result']);
	}	else if ($report_entry['type'] == 'entry') {
		$result = $report_entry['result'];
		$log_entry .= ' params = '.$report_entry['params'].
		' result = '.$result['title'].' by '.$result['author'].
		', with '.count($result['comments']).' comments';
	}

	if ($report_entry['error']) {
		log_error('Error for step '.
			$report_entry['type'].' '.$report_entry['params'].': '.
			$report_entry['error']);
	}	else {
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

function _scraping_build_author_cache($db, $event_id) {
  $results = db_query($db, "SELECT uid_author, author FROM entry WHERE event_id = ?", 's', $event_id);
  $cache = [];
  foreach ($results as $result) {
    $cache[$result['uid_author']] = $result['author'];
  }
  return $cache;
}

/*
	How it works:
		- Scraping is cut in small "steps" to better control execution time.
		  Every step, we either:
			- Read the UIDs page
			- Fetch info for an entry

		1. Read the UIDs pages and store all the UIDs missing from the DB in the "setting" table
		2. If we found missing UIDs, then run through them to scrape them.
		   Otherwise, run through all existing UIDs, while at the same time
		   making sure the 9 front page entries were updated during the last X minutes.
		3. Back to 1

*/
function scraping_run($db) {
	static $SETTING_EVENT_ID = 'scraping_event_id';
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';
	static $SETTING_LAST_READ_ENTRY = 'scraping_last_read_entry';
	static $SETTING_LAST_READ_UIDS_PAGE = 'scraping_last_read_uids_page';

	// Init
	$report = array();
	$report['steps'] = array();
	$start_time = microtime(true);
	$last_step_time = $start_time;
	$steps = 0;
	$average_step_duration = 0;
	$over = false;
	$front_page_max_age = ceil(LDFF_SCRAPING_FRONTPAGE_MAX_AGE / 60);

	// Reset scraping on event ID change
	$event_id_cache = setting_read($db, $SETTING_EVENT_ID, -1);
	if ($event_id_cache != LDFF_ACTIVE_EVENT_ID) {
		setting_write($db, $SETTING_MISSING_UIDS, '');
		setting_write($db, $SETTING_LAST_READ_UIDS_PAGE, -1);
		setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
		setting_write($db, $SETTING_EVENT_ID, LDFF_ACTIVE_EVENT_ID);
	}

	$event_id = LDFF_ACTIVE_EVENT_ID;
  $author_cache = _scraping_build_author_cache($db, $event_id);

	$fp_stmt = mysqli_prepare($db, "SELECT entry.uid FROM entry
		INNER JOIN(SELECT uid FROM entry WHERE event_id = ?
		ORDER BY coolness DESC, last_updated DESC LIMIT 9) AS entry2 ON entry.uid = entry2.uid
		AND entry.event_id  = ?
		AND entry.last_updated < DATE_SUB(NOW(), INTERVAL ".$front_page_max_age." MINUTE) LIMIT 1");
	mysqli_stmt_bind_param($fp_stmt, 'ss', $event_id, $event_id);


	$stmt = mysqli_prepare($db, "SELECT uid FROM entry WHERE uid > ? AND event_id = ? ORDER BY uid LIMIT 2");

	// Loop until we're about to reach the timeout
	while (!$over) {
		$over = $last_step_time - $start_time + $average_step_duration > LDFF_SCRAPING_TIMEOUT;

		$report_entry = array();

		// Read UIDs page
		$last_read_entry = setting_read($db, $SETTING_LAST_READ_ENTRY, -1);
		if ($last_read_entry == -1) {
			$last_read_page = setting_read($db, $SETTING_LAST_READ_UIDS_PAGE, -1);
			$uids = _scraping_run_step_uids($db, $last_read_page + 1);
			$report_entry['type'] = 'uids';
			$report_entry['result'] = $uids;
			$report_entry['error'] = mysqli_error($db);

			if (count($uids) > 0) {
				setting_write($db, $SETTING_LAST_READ_UIDS_PAGE, $last_read_page + 1);
			} else {
				// Switch to entry info mode
				setting_write($db, $SETTING_LAST_READ_UIDS_PAGE, -1);
				setting_write($db, $SETTING_LAST_READ_ENTRY, 0);
			}
		}

		// Fetch entry info
		else {
			$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
			$next_uid = null;

			// Choose what entry we need to scrape...
			// ...do we go through missing UIDs?
			$fetching_missing_uid = false;
			$refresh_font_page_uid = false;
			while (strlen($missing_uids) > 0 && !$fetching_missing_uid) {
				$missing_uids_array = explode(',', $missing_uids, 2);
				$uid = $missing_uids_array[0];
				$missing_uids = $missing_uids_array[1];
				if ($uid != '' && !_scraping_is_uid_blacklisted($uid)) {
					$fetching_missing_uid = true;
				}
			}
			if (!$fetching_missing_uid) {
				// ...or do we update a front page entry?
				mysqli_stmt_execute($fp_stmt);
				$results = mysqli_stmt_get_result($fp_stmt);
				if (mysqli_num_rows($results) > 0) {
					$data = mysqli_fetch_array($results);
					$uid = $data['uid'];
					$refresh_font_page_uid = true;
				}

				if (!$refresh_font_page_uid) {
					// ...or just go through all existing entries?
					mysqli_stmt_bind_param($stmt, 'is', $last_read_entry, $event_id);
					mysqli_stmt_execute($stmt);
					$results = mysqli_stmt_get_result($stmt);
					if (mysqli_num_rows($results) > 0) {
						$data = mysqli_fetch_array($results);
						$uid = $data['uid'];
						$data = mysqli_fetch_array($results);
						$next_uid = $data['uid'];
					}
				}
			}

			if ($uid != null) {
				$params = $uid . ',' . (($fetching_missing_uid)?'insert':'update');
				if ($refresh_font_page_uid) {
					$params .= ',frontpage';
				}

				$entry = _scraping_run_step_entry($db, $event_id, $uid, $author_cache, false);
				$report_entry['type'] = 'entry';
				$report_entry['params'] = $params;
				$report_entry['result'] = $entry;
				$report_entry['error'] = mysqli_error($db);

				$switch_to_uids_mode = false;
				if ($fetching_missing_uid) {
					setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
					if ($missing_uids == '') {
						$switch_to_uids_mode = true;
					}
				}	else if (!$refresh_font_page_uid) {
					setting_write($db, $SETTING_LAST_READ_ENTRY, $uid);
					if ($next_uid == null) {
						$switch_to_uids_mode = true;
					}
				}

				if ($switch_to_uids_mode) {
					setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
					setting_write($db, $SETTING_LAST_READ_UIDS_PAGE, -1);
				}
			} else {
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
		$average_step_duration = round(1. * ($last_step_time - $start_time) / $steps, 3);
	}

	mysqli_stmt_close($fp_stmt);
	mysqli_stmt_close($stmt);

	$report['step_count'] = $steps;
	$report['average_step_duration'] = round($average_step_duration);
	$report['total_duration'] = round($last_step_time - $start_time, 3);
	$report['slept_per_step'] = LDFF_SCRAPING_SLEEP;
	$report['timeout'] = LDFF_SCRAPING_TIMEOUT;
	_scraping_log_report($report);

	return $report;
}

?>
