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

function db_query($db, $sql, $bind_params_str, ...$params) {
	$result = false;
	if ($stmt = mysqli_prepare($db, $sql)) {
		if (count($params) == 1 && gettype($params[0]) == 'array') {
			$bind_success = mysqli_stmt_bind_param($stmt, $bind_params_str, ...$params[0]);
		}
		else {
			$bind_success = mysqli_stmt_bind_param($stmt, $bind_params_str, ...$params);
		}
		if ($bind_success) {
			if ($result = mysqli_stmt_execute($stmt)) {
				if ($raw_result = mysqli_stmt_get_result($stmt)) {
					$result = array();
					while ($result[] = mysqli_fetch_array($raw_result));
				}
			}
		}

		mysqli_stmt_close($stmt);
	}
	return $result;
}

function db_explain_query($db, $query, $bind_params_str, $params) {
	$stmt = mysqli_prepare($db, "EXPLAIN $query");
	mysqli_stmt_bind_param($stmt, $bind_params_str, ...$params);
	if (!mysqli_stmt_execute($stmt)) {
		mysqli_stmt_close($stmt);
		log_error_and_die('Failed to get query explanation', mysqli_error($db));
	}
	$results = mysqli_stmt_get_result($stmt);
	echo '<pre>';
	echo "$query;\n(params: ";
	foreach ($params as $key => $param) {
		if ($key != 0) {
			echo ", ";
		}
		echo $param;
	}
	echo ")\n\n";
	while ($row = mysqli_fetch_assoc($results)) {
		foreach ($row as $key => $value) {
			echo "$key: $value, ";
		}
		echo "\n";
	}
	echo '</pre>';
	mysqli_stmt_close($stmt);
}

?>
