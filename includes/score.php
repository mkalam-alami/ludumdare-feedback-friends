<?php

function score_evaluate_comment($author_uid, $entry_uid, $comment, $previous_comments_score) {
	$score = 0;
	if ($author_uid != $entry_uid) {
		$text_length = strlen(util_sanitize($comment));
		if ($text_length > 300) { // Elaborate comments
			$score = 3;
		}
		else if ($text_length > 100) { // Interesting comments
			$score = 2;
		}
		else { // Short comments
			$score = 1;
		}
	}
	$score = max(0, min($score - $previous_comments_score, 3 - $previous_comments_score));
	return $score;
}

function score_comments_given($db, $event_id, $uid) {
	$stmt = mysqli_prepare($db, "SELECT SUM(score) FROM comment
		WHERE event_id = ? AND uid_author = ? AND uid_entry != ?");
	mysqli_stmt_bind_param($stmt, 'sii', $event_id, $uid, $uid);
	mysqli_stmt_execute($stmt);
	$result = mysqli_stmt_get_result($stmt);
	$row = mysqli_fetch_array($result);
	mysqli_stmt_close($stmt);
	return $row[0];
}

function score_comments_received($db, $event_id, $uid) {
		$stmt = mysqli_prepare($db, "SELECT SUM(score) FROM comment
			WHERE event_id = ? AND uid_entry = ? AND uid_author != ?
			AND uid_author NOT IN(".(LDFF_UID_BLACKLIST?LDFF_UID_BLACKLIST:"''").")");

		mysqli_stmt_bind_param($stmt, 'sii', $event_id, $uid, $uid);
		mysqli_stmt_execute($stmt);
		$result = mysqli_stmt_get_result($stmt);
		$row = mysqli_fetch_array($result);
		mysqli_stmt_close($stmt);
		return $row[0];
}

function score_coolness($given, $received) {
	// This formula boosts a little bit low scores (< 30) to ensure everybody gets at least some comments,
	// and to reward people for posting their first comments. It also nerfs & caps very active commenters to prevent
	// them from trusting the front page. Finally, negative scores are not cool so we use 100 as the origin.
	// NB. It is inspired by the actual LD sorting equation: D = 50 + R - 5*sqrt(min(C,100))
	// (except that here, higher is better)
	return floor( max(0, 74 + 8.5 * sqrt(10 + min($given, 100)) - $received) );
}

function score_average($comments) {
	$total = 0;
	$count = 0;
	foreach ($comments as $comment) {
		$total += $comment['score'];
		$count++;
	}

	if ($count > 0) {
		return round(1. * $total / $count, 1);
	}
	else {
		return 0;
	}
}

?>
