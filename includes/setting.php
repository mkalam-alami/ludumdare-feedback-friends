<?php

function setting_read($db, $id, $default_value = null) {
	if ($results = mysqli_query($db, "SELECT value FROM setting WHERE id = '$id'")) {
		if (mysqli_num_rows($results) > 0) {
			$setting = mysqli_fetch_array($results);
			return $setting['value'];
		}
	}
	return $default_value;
}

function setting_write($db, $id, $value) {
	if (setting_read($db, $id, null) === null) {
		mysqli_query($db, "INSERT INTO setting(id,value) VALUES('$id', '$value')")
			or die("Failed to insert setting '$id' with value '$value'");
	}
	else {
		mysqli_query($db, "UPDATE setting SET value = '$value' WHERE id = '$id'")
			or die("Failed to update setting '$id' with value '$value'");
	}
}

?>