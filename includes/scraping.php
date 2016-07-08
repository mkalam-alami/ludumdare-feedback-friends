<?php 

// TODO Sanitize inputs better
function _escape($string) {
	return addslashes($string);
}

function _scraping_run_step_uids($db, $page) {
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';

	$uids = http_fetch_uids($page);

	if (count($uids) > 0) {
		$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
		foreach ($uids as $uid) {
			if (strpos($missing_uids, $uid.',') === false) {
				$missing_uids .= $uid.',';
			}
		}
		setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
	}

	return $uids;
}

function _scraping_run_step_entry($db, $uid) {
	$entry = http_fetch_entry($uid);
	$timestamp = time();

	mysqli_query($db, "UPDATE entry SET 
			author = '" . _escape($entry['author']) . "',
			title = '" . _escape($entry['title']) . "',
			type = '" . _escape($entry['type']) . "',
			description = '" . _escape($entry['description']) . "',
			platforms = '" . _escape($entry['platforms']) . "',
			picture = '" . _escape($entry['picture']) . "',
			timestamp = '" . $timestamp . "'
			WHERE uid = '$uid'");
	if (mysqli_affected_rows($db) == 0) {
		mysqli_query($db, "INSERT INTO 
			entry(uid,author,title,type,description,platforms,picture,timestamp) 
			VALUES('$uid',
				'" . _escape($entry['author']). "',
				'" . _escape($entry['title']). "',
				'" . _escape($entry['type']). "',
				'" . _escape($entry['description']). "',
				'" . _escape($entry['platforms']). "',
				'" . _escape($entry['picture']). "',
				'" . $timestamp. "'
				)");
	}

	return $entry;
}

/*
	How it works:
		- Every step, we either
			- Read an UID page
			- Fetch info for a entry
			- Switch between the two modes: "UIDs" & "Fetch"
		- We start in UIDs mode
		- While in UIDs mode, read UID pages. If we find missing UIDs, fetch entries info for them before
			continuing to read UID pages (otherwise we'd fill up the 'scraping_missing_uids'
			setting to saturation in the first run). Once we've read all pages, switch to fetch mode.
		- While in fetch mode, run through all existing entries by UID, and refresh them.
			Once we've read all entries, switch to UIDs mode.


*/
function scraping_run($db, $timeout = 10) {
	static $SETTING_LAST_READ_PAGE = 'scraping_last_read_page';
	static $SETTING_MISSING_UIDS = 'scraping_missing_uids';
	static $SETTING_LAST_READ_ENTRY = 'scraping_last_read_entry';

	$report = array();
	$report['steps'] = array();

	// Time management init
	$micro_timeout = $timeout;
	$start_time = microtime(true);
	$last_step_time = $start_time;
	$steps = 0;
	$average_step_duration = 0;
	$over = false;

	// Loop until we're about to reach the timeout
	while (!$over) {
		$over = $last_step_time - $start_time + $average_step_duration > $micro_timeout;

		$report_entry = array();

		// Read UIDs page
		$missing_uids = setting_read($db, $SETTING_MISSING_UIDS, '');
		if ($missing_uids == '') {
			$last_read_page = setting_read($db, $SETTING_LAST_READ_PAGE, 0);
			if ($last_read_page != -1) {
				$page = $last_read_page + 1;
				$uids = _scraping_run_step_uids($db, $page);
				if (count($uids) > 0) {
					setting_write($db, $SETTING_LAST_READ_PAGE, $page);
					$report_entry['type'] = 'uids';
					$report_entry['params'] = $page;
					$report_entry['result'] = $uids;
					$report_entry['message'] = mysqli_error($db);
				}
				else {
					setting_write($db, $SETTING_LAST_READ_PAGE, -1);
					setting_write($db, $SETTING_LAST_READ_ENTRY, 0);
					$report_entry['type'] = 'switch_to_entry';
				}
			}
		}

		// Fetch entry info
		$last_read_entry = setting_read($db, 'scraping_last_read_entry', 0);
		if (!isset($report_entry['type']) && ($last_read_entry != -1 || $missing_uids != '')) {
			
			// Start going through missing UIDs, then run through all entries
			$uid = null;
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
				$results = mysqli_query($db, "SELECT uid FROM entry WHERE uid > '$last_read_entry' LIMIT 1");
				if (mysqli_num_rows($results) > 0) {
					$data = mysqli_fetch_array($results);
					$uid = $data['uid'];
				}
			}

			if ($uid != null) {
				$entry = _scraping_run_step_entry($db, $uid);
				$report_entry['type'] = 'entry';
				$report_entry['params'] = $uid . ',' . ($fetching_missing_uid?'insert':'update');
				$report_entry['result'] = $entry;
				$report_entry['message'] = mysqli_error($db);

				if ($fetching_missing_uid) {
					setting_write($db, $SETTING_MISSING_UIDS, $missing_uids);
				}
				else {
					setting_write($db, $SETTING_LAST_READ_ENTRY, $uid);
				}
			}
			else {
				setting_write($db, $SETTING_LAST_READ_PAGE, 0);
				setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
				$report_entry['type'] = 'switch_to_uids';
			}
		}
	
		if (!$over && !LDFF_SCRAPING_PSEUDO_CRON) {
			usleep(LDFF_SCRAPING_SLEEP * 1000000);
		}

		$time = microtime(true);
		$report_entry['duration'] = $time - $last_step_time;
		$report['steps'][] = $report_entry;
		$last_step_time = $time;
		$steps++;
		$average_step_duration = ($last_step_time - $start_time) / $steps;
	}

	$report['step_count'] = $steps;
	$report['average_step_duration'] = $average_step_duration;
	$report['total_duration'] = $last_step_time - $start_time;
	$report['slept_per_step'] = LDFF_SCRAPING_SLEEP;
	$report['timeout'] = $timeout;

	// TODO Format and log report in file
	return $report;
}


?>