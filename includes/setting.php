<?php

function setting_read($db, $id, $default_value = null) {
	$ret_val = $default_value;
	$stmt = mysqli_prepare($db, "SELECT value FROM setting WHERE id = ?");
	mysqli_stmt_bind_param($stmt, 's', $id);
	mysqli_stmt_execute($stmt);
	if ($results = mysqli_stmt_get_result($stmt)) {
		if (mysqli_num_rows($results) > 0) {
			$setting = mysqli_fetch_array($results);
			$ret_val = $setting['value'];
		}
	}
	mysqli_stmt_close($stmt);
	return $ret_val;
}

function setting_write($db, $id, $value) {
	if (setting_read($db, $id, null) === null) {
		$stmt = mysqli_prepare($db, "INSERT INTO setting(id,value) VALUES(?, ?)");
		mysqli_stmt_bind_param($stmt, 'ss', $id, $value);
		if(!mysqli_stmt_execute($stmt)){
			mysqli_stmt_close($stmt);
			die("Failed to insert setting '$id' with value '$value'");
		}
		mysqli_stmt_close($stmt);
	}
	else {

		$stmt = mysqli_prepare($db, "UPDATE setting SET value = ? WHERE id = ?");
		mysqli_stmt_bind_param($stmt, 'ss', $value, $id);
		if(!mysqli_stmt_execute($stmt)){
			mysqli_stmt_close($stmt);
			die("Failed to update setting '$id' with value '$value'");
		}
		mysqli_stmt_close($stmt);
	}
}

?>
