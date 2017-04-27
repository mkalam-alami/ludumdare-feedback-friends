<?php

// TODO Password-protect

require_once(__DIR__ . '/includes/init.php');

util_require_admin();

$db = db_connect();

set_time_limit(999);

// Show cache status
if (isset($_GET['cache'])) {
	echo '<pre>';
	print_r(apcu_cache_info());
	echo '</pre>';

	if (isset($_GET['clear'])) {
		apcu_clear_cache();
		echo '<h1>CACHE CLEARED.</h1>';
	}
}

// Recompute coolness
else if (isset($_GET['coolness'])) {
	$event_id = LDFF_ACTIVE_EVENT_ID;
	if (isset($_GET['event_id'])) {
		$event_id = util_sanitize_query_param('event_id');
	}

	$first_uid = 0;
	if (isset($_GET['uid'])) {
		$first_uid = util_sanitize_query_param('uid');
	}

	echo 'Refreshing coolness... ';

	$update_stmt = mysqli_prepare($db, "UPDATE entry SET
			comments_given = ?,
			comments_received = ?,
			coolness = ?
			WHERE uid = ? AND event_id = ?");


	$stmt = mysqli_prepare($db, "SELECT uid FROM entry WHERE uid > ? AND event_id = ? ORDER BY uid");
	mysqli_stmt_bind_param($stmt, 'is', $first_uid, $event_id);
	mysqli_stmt_execute($stmt);
	if(!mysqli_stmt_execute($stmt)){
		mysqli_stmt_close($stmt);
		die(mysqli_error($db));
	}

	$results = mysqli_stmt_get_result($stmt);
	while ($row = mysqli_fetch_array($results)) {
		$uid = $row['uid'];

		$comments_given = score_comments_given($db, $event_id, $uid);
		$comments_received = score_comments_received($db, $event_id, $uid);
		$coolness = score_coolness($comments_given, $comments_received);

		// Update entry table
		mysqli_stmt_bind_param($update_stmt, 'iiiis', $comments_given, $comments_received, $coolness, $uid, $event_id);
		if(!mysqli_stmt_execute($update_stmt)){
			mysqli_stmt_close($update_stmt);
			die(mysqli_error($db));
		}
		echo "<a href='?coolness&uid=$uid'>$uid</a> ";
	}
	mysqli_stmt_close($stmt);
	mysqli_stmt_close($update_stmt);
}

// (!!!) Reset whole event (!!!)
else if (isset($_GET['reset'])) {
	$event_id = util_sanitize_query_param('reset');
 	if (isset($_POST['confirm'])) {
		db_query($db, "DELETE FROM entry WHERE event_id = ?", 's', $event_id);
		db_query($db, "DELETE FROM comment WHERE event_id = ?", 's', $event_id);
		db_query($db, "DELETE FROM comment WHERE event_id = ?", 's', $event_id);
		db_query($db, "DELETE FROM setting WHERE id = 'scraping_event_id'");
		echo 'Done.';
 	}	else {
 		echo '<form method="post"><input name="confirm" type="submit" value="Reset event '.$event_id.'?" /></form>';

 	}
}

// XXX Trophy experiments
else if (isset($_GET['trophy'])) {
	util_require_admin();

	$event_id = util_sanitize_query_param('trophy');
	if (!$event_id) {
		$event_id = LDFF_ACTIVE_EVENT_ID;
	}
	$keywords = explode(',', util_sanitize_query_param('keywords'));

	$uids = [];
	$scores = [];
	foreach ($keywords as $keyword) {
		$rows = db_query($db, "SELECT uid_entry, COUNT(*) as score FROM comment WHERE comment LIKE ? AND event_id = ? GROUP BY uid_entry HAVING score > 0",
			'ss', '%'.$keyword.'%', $event_id);
		foreach ($rows as $row) {
			$uid = $row['uid_entry'];
			if (!isset($uids[$uid])) {
				$uids[$uid] = $uid;
				$scores[$uid] = 0;
			}
			$scores[$uid] += $row['score']; 
		}
	}
    
    // Normalize scores by comment count
	foreach ($uids as $uid) {
		$rows = db_query($db, "SELECT COUNT(*) as comment_count FROM comment WHERE event_id = ? AND uid_entry = ?",
			'si', $event_id, $uid);
		foreach ($rows as $row) {
            if ($row['comment_count'] > 10) {
                $scores[$uid] = floor($scores[$uid] * 10000. / $row['comment_count']);
            }
		}
	}
    
	array_multisort($scores, SORT_DESC, $uids);

	$template = $mustache->loadTemplate('cartridge');
	$context = array(
		'event_url' => is_numeric($event_id) ? LD_WEB_ROOT : (LD_OLD_WEB_ROOT . $event_id . "/?action=preview")
	);
	echo '<html><head><link rel="stylesheet" type="text/css" href="static/css/bootstrap.min.css" /><link rel="stylesheet" type="text/css" href="static/css/site.css" /></head><body style="background-image: none; background-color: white">';
	for ($i = 0; $i < 10; $i++) {
		if (isset($uids[$i])) {
			echo "<a href='index.php?event=$event_id&uid=".$uids[$i]."'>".$uids[$i].": ".$scores[$i]." points</a><br />";
			$entries = db_query($db, "SELECT * FROM entry WHERE uid = ? AND event_id = ?", 'is', $uids[$i], $event_id);
			$entry = $entries[0];
			$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
			$context['entry'] = $entry;
			echo $template->render($context);
			echo '<br /><br />';
		}
	}
	echo '</body></html>';
}



else {
	echo '?cache[&clear], ?coolness, ?reset=[event], ?trophy=[event]&keywords=[keywords]';
}

mysqli_close($db);

?>
