<?php

// TODO Password-protect

require_once(__DIR__ . '/includes/init.php');

$db = db_connect();

set_time_limit(999);

if (isset($_GET['coolness'])) {

	$first_uid = 0;
	if (isset($_GET['uid'])) {
		$first_uid = $_GET['uid'];
	}

	echo 'Refreshing coolness... ';

	$results = mysqli_query($db, "SELECT uid FROM entry WHERE uid > $first_uid ORDER BY uid") or die(mysqli_error($db)); 
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
				WHERE uid = '$uid'") or die(mysqli_error($db));
		echo "<a href='?coolness&uid=$uid'>$uid</a> ";
	}

}

mysqli_close($db);

echo '<br />Done.';

?>