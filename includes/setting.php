<?php

function setting_read($db, $id, $default_value = null) {
	if ($res = mysqli_query($db, "SELECT value FROM setting WHERE id = '$id'")) {
		if(mysqli_num_rows($res) > 0) {
			$setting = mysqli_fetch_array($res);
			return $setting['value'];
		}
	}
	return $default_value;
}

function setting_write($db, $id, $value) {
	mysqli_query($db, "UPDATE setting SET value = '$value' WHERE id = '$id'")
		or die("Failed to update setting '$id' with value '$value'");
	if (mysqli_affected_rows($db) == 0) {
		mysqli_query($db, "INSERT INTO setting(id,value) VALUES('$id', '$value')")
			or die("Failed to insert setting '$id' with value '$value'");
	}
}

?>