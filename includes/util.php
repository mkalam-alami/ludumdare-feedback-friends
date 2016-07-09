<?php

function util_query_param($key) {
	return preg_replace("/\W|_/", '', $_GET[$key]); // only keep alpha-numeric chars
}

function util_get_picture_path($uid) {
	return 'data/'.$uid.'.jpg';
}

?>