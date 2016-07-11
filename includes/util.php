<?php

function util_sanitize($value) {
	return preg_replace("/(([\w*,]*))/", '$0', $value); // only keep alpha-numeric chars
}

function util_sanitize_query_param($key) {
	if (isset($_GET[$key])) {
		return util_sanitize($_GET[$key]);
	}
	else {
		return '';
	}
}

function util_get_picture_path($uid) {
	return 'data/'.$uid.'.jpg';
}

?>