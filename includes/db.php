<?php

function db_connect() {
	global $db;
	
	$db = mysqli_connect(LDFF_MYSQL_HOST, LDFF_MYSQL_USERNAME, LDFF_MYSQL_PASSWORD, LDFF_MYSQL_DATABASE);
	if(!$db) {
		die("Database connection error");
	}	
	mysqli_select_db($db, LDFF_MYSQL_DATABASE) or die("Database selection failure");	
	mysqli_query($db, 'SET character_set_results="utf8"') or die("Database result type setting failure");
	mysqli_set_charset($db, "utf8");
		
	return $db;
}

function db_select_single_row($db, $query) {
	$results = mysqli_query($db, $query);
	return mysqli_fetch_array($results);
}

function db_select_single_value($db, $query) {
	$results = mysqli_query($db, $query);
	$row = mysqli_fetch_array($results);
	return $row[0];
}

?>