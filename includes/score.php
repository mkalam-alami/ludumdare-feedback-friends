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
	$score = max($score - $previous_comments_score, 0);
	return $score;
}

function score_comments_given($db, $uid) { 
	return db_select_single_value($db, "SELECT SUM(score) FROM comment WHERE uid_author = '$uid' AND uid_entry != '$uid'");
}

function score_comments_received($db, $uid) { 
	return db_select_single_value($db, "SELECT SUM(score) FROM comment WHERE uid_entry = '$uid' AND uid_author != '$uid'");
}

function score_coolness($given, $received) {
	return 100 + $given - $received; // Don't use zero as the origin because negative scores are not cool
}

function score_average($comments) {
	$total = 0;
	$count = 0;
	foreach ($comments as $comment) {
		$total += $comment['score'];
		$count++;
	}

	if ($count > 0) {
		return round(1. * $total / $count, 2);
	}
	else {
		return 0;
	}
}


?>