<?php 

// TODO Sanitize inputs better
function _escape($string) {
	return addslashes($string);
}

function _scraping_run_step_uids($db, $page) {
	$uids = http_fetch_uids($page);

	// TODO Maybe store the unimported UIDs elsewhere to prevent spamming the table with empty entries
	mysqli_query($db, "START TRANSACTION");
	foreach ($uids as $uid) {
		mysqli_query($db, "INSERT IGNORE INTO entry(uid) VALUES($uid)");
	}
	mysqli_query($db, "COMMIT");

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
	/*if (mysqli_affected_rows($db) == 0) {
		mysqli_query($db, "INSERT INTO 
			setting(uid,author,title,type,description,platforms,picture,timestamp) 
			VALUES('$uid',
				'" . $entry['author']. "',
				'" . $entry['title']. "',
				'" . $entry['type']. "',
				'" . $entry['description']. "',
				'" . $entry['platforms']. "',
				'" . $entry['picture']. "',
				'" . $timestamp. "'
				)");
	}*/

	return $entry;
}

function scraping_run($db, $timeout = 10) {
	static $SETTING_LAST_READ_PAGE = 'scraping_last_read_page';
	static $SETTING_LAST_READ_ENTRY = 'scraping_last_read_entry';

	$report = array();
	$report['steps'] = array();

	// Time management init
	$micro_timeout = $timeout;
	$start_time = microtime(true);
	$last_step_time = $start_time;
	$steps = 0;
	$average_step_duration = 0;

	// Loop
	while ($last_step_time - $start_time + $average_step_duration*2 < $micro_timeout) { // Maximize our chances to stay below timeout
		$report_entry = array();

		// Fetch UIDs page
		$last_read_page = setting_read($db, $SETTING_LAST_READ_PAGE, 0);
		if ($last_read_page != -1) {
			$page = $last_read_page + 1;
			$uids = _scraping_run_step_uids($db, $page);
			if (count($uids) > 0) {
				setting_write($db, $SETTING_LAST_READ_PAGE, $page);
				$report_entry['type'] = 'uids';
				$report_entry['param'] = $page;
				$report_entry['result'] = $uids;
				$report_entry['message'] = mysqli_error($db);
			}
			else {
				setting_write($db, $SETTING_LAST_READ_PAGE, -1);
				setting_write($db, $SETTING_LAST_READ_ENTRY, 0);
				$report_entry['type'] = 'switch_to_entry';
			}
		}

		// Fetch entry info
		$last_read_entry = setting_read($db, 'scraping_last_read_entry', 0);
		if (!isset($report_entry['type']) && $last_read_entry != -1) {
			$results = mysqli_query($db, "SELECT uid FROM entry WHERE uid > '$last_read_entry' LIMIT 1");
			if (mysqli_num_rows($results) > 0) {
				$data = mysqli_fetch_array($results);
				$uid = $data['uid'];
				$entry = _scraping_run_step_entry($db, $uid);
				$report_entry['type'] = 'entry';
				$report_entry['param'] = $uid;
				$report_entry['result'] = $entry;
				$report_entry['message'] = mysqli_error($db);
				setting_write($db, $SETTING_LAST_READ_ENTRY, $uid);
			}
			else {
				setting_write($db, $SETTING_LAST_READ_PAGE, 0);
				setting_write($db, $SETTING_LAST_READ_ENTRY, -1);
				$report_entry['type'] = 'switch_to_uids';
			}
		}

		$time = microtime(true);
		$report_entry['duration'] = $time - $last_step_time;
		$report['steps'][] = $report_entry;
		$last_step_time = $time;
		$steps++;
		$average_step_duration = ($last_step_time - $start_time) / $steps;

		if ($steps > 10) break;
	}

	$report['step_count'] = $steps;
	$report['average_step_duration'] = $average_step_duration;
	$report['duration'] = $last_step_time - $start_time;
	$report['timeout'] = $timeout;

	return $report;
}


?>