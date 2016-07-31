<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

$event_id = util_sanitize_query_param('event');

$cache_key = $event_id;
$gzip_output = cache_read($cache_key);
if (!$gzip_output) {
	$stmt = mysqli_prepare($db,
		"SELECT entry.uid, entry.author, entry.title, entry.type, entry.platforms, entry.comments_given, entry.comments_received, entry.coolness, entry.last_updated, GROUP_CONCAT(comment.uid_author)
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
		array_push($json, array(
			'uid' => (int)$row[0],
			'author' => $row[1],
			'title' => $row[2],
			'type' => $row[3],
			'platforms' => $row[4],
			'comments_given' => $row[5],
			'comments_received' => $row[6],
			'coolness' => $row[7],
			'last_updated' => $row[8],
			'commenter_ids' => array_map('intval', explode(',', $row[9])),
		));
	}

	$gzip_output = gzencode(json_encode($json));
	cache_write($cache_key, $gzip_output);
}

header('Content-Type: application/json');
header('Content-Encoding: gzip');
header('Content-Length: '.strlen($gzip_output));

print($gzip_output);

mysqli_stmt_close($stmt);

mysqli_close($db);

?>
