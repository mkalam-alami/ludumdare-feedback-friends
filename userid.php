<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

$event_id = util_sanitize_query_param('event');
$query = util_sanitize_query_param('query');

$sql = "SELECT uid, author FROM entry WHERE event_id = '$event_id' AND author LIKE '%$query%' ORDER BY author LIMIT 20;";
$results = mysqli_query($db, $sql) or log_error_and_die('Failed to fetch username', mysqli_error($db)); 

$json = [];
while ($row = mysqli_fetch_row($results)) {
	array_push($json, array(
		'userid' => (int)$row[0],
		'username'=> $row[1],
	));
}

header('Content-Type: application/json');
print(json_encode($json));

mysqli_close($db);

?>
