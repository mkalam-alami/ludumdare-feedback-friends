<?php

function setting_read($db, $id, $default_value = null) {
	$ret_val = $default_value;
	$results = db_query($db, "SELECT value FROM setting WHERE id = ?", 's', $id);
	if (count($results) > 0) {
		$setting = $results[0];
		$ret_val = $setting['value'];
	}
	return $ret_val;
}

function setting_write($db, $id, $value) {
	if (setting_read($db, $id, null) === null) {
		if (!db_query($db, "INSERT INTO setting(id,value) VALUES(?, ?)",
				'ss', $id, $value)) {
			die("Failed to insert setting '$id' with value '$value'");
		}
	}	else if (!db_query($db, "UPDATE setting SET value = ? WHERE id = ?",
			'ss', $value, $id)) {
		die("Failed to insert setting '$id' with value '$value'");
	}
}

?>
