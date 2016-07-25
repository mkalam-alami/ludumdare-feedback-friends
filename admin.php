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

	$first_uid = 0;
	if (isset($_GET['uid'])) {
		$first_uid = util_sanitize_query_param('uid');
	}

	echo 'Refreshing coolness... ';

	$results = mysqli_query($db, "SELECT uid FROM entry WHERE uid > $first_uid AND event_id = '".LDFF_ACTIVE_EVENT_ID."' ORDER BY uid") or die(mysqli_error($db)); 
	while ($row = mysqli_fetch_array($results)) {
		$uid = $row['uid'];

		$comments_given = score_comments_given($db, $uid);
		$comments_received = score_comments_received($db, $uid);
		$coolness = score_coolness($comments_given, $comments_received);

		// Update entry table
		mysqli_query($db, "UPDATE entry SET 
				comments_given = '$comments_given',
				comments_received = '$comments_received',
				coolness = '$coolness'
				WHERE uid = '$uid' AND event_id = '".LDFF_ACTIVE_EVENT_ID."'") or die(mysqli_error($db));
		echo "<a href='?coolness&uid=$uid'>$uid</a> ";
	}

}

// (!!!) Reset whole event (!!!)
else if (isset($_GET['reset']) && $_GET['reset'] == LDFF_ACTIVE_EVENT_ID) {
	mysqli_query($db, "DELETE FROM entry WHERE event_id = '".LDFF_ACTIVE_EVENT_ID."'") or die(mysqli_error($db)); 
	mysqli_query($db, "DELETE FROM comment WHERE event_id = '".LDFF_ACTIVE_EVENT_ID."'") or die(mysqli_error($db)); 
	mysqli_query($db, "DELETE FROM setting WHERE id = 'scraping_event_id'") or die(mysqli_error($db)); 
}

else {
	echo '?cache[&clear], ?coolness, ?reset=[event]';
}

mysqli_close($db);

echo '<br />Done.';

?>