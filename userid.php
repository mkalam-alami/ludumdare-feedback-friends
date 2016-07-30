<?php

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

$event_id = util_sanitize_query_param('event');
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

mysqli_close($db);

?>
