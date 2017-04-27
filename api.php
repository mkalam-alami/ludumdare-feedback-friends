<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();
$action = util_sanitize_query_param('action');

header('Access-Control-Allow-Origin: *');

if ($action == 'eventsummary') {

	$event_id = util_sanitize_query_param('event');

	$cache_key = $event_id;
	$gzip_output = cache_read($cache_key);
	if (!$gzip_output) {
		$stmt = mysqli_prepare($db,
			"SELECT entry.uid, entry.author, entry.title, entry.type, entry.platforms, entry.comments_given, entry.comments_received, entry.coolness, entry.last_updated, entry.entry_page, GROUP_CONCAT(comment.uid_author)
			 FROM entry
				  LEFT OUTER JOIN comment ON entry.event_id = comment.event_id AND entry.uid = comment.uid_entry
			 WHERE entry.event_id = ?
			 GROUP BY entry.uid;");
		mysqli_stmt_bind_param($stmt, 's', $event_id);
		if (!mysqli_stmt_execute($stmt)) {
			mysqli_stmt_close($stmt);
			log_error_and_die('Failed to fetch event summary data', mysqli_error($db));
		}
		$results = mysqli_stmt_get_result($stmt);

		$json = [];
		while ($row = mysqli_fetch_row($results)) {
			// keys are minified manually to save room
			array_push($json, array(
				'_' => (int)$row[0], // uid
				'a' => $row[1], // author
				't' => $row[2], // title
				'y' => $row[3], // type
				'p' => $row[4], // platforms
				'g' => $row[5], // comments_given
				'r' => $row[6], // comments_received
				'c' => $row[7], // coolness
				'u' => $row[8], // last_updated
				'e' => $row[9], // entry_page
				'i' => $row[10], // commenter_ids
			));
		}

		$gzip_output = gzencode(json_encode($json));
		cache_write($cache_key, $gzip_output);
	}

	header('Content-Type: application/json');
	header('Content-Encoding: gzip');
	header('Content-Length: ' . strlen($gzip_output));

	print($gzip_output);

	mysqli_stmt_close($stmt);

}

else if ($action == 'userid') {

	$query = util_sanitize_query_param('query');

	$stmt = mysqli_prepare($db, "SELECT DISTINCT(uid), author FROM entry WHERE author LIKE ? ORDER BY author LIMIT 20");
	$param = "%$query%";
	mysqli_stmt_bind_param($stmt, 's', $param);
	if(!mysqli_stmt_execute($stmt)){
		mysqli_stmt_close($stmt);
		log_error_and_die('Failed to fetch username', mysqli_error($db));
	}
	$results = mysqli_stmt_get_result($stmt);

	$json = [];
	while ($row = mysqli_fetch_row($results)) {
		array_push($json, array(
			'userid' => (int)$row[0],
			'username'=> $row[1],
		));
	}

	header('Content-Type: application/json');
	print(json_encode($json));

	mysqli_stmt_close($stmt);
	
}

mysqli_close($db);

?>
