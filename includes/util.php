<?php

function util_sanitize($value) {
	// Only keep alpha-numeric chars
	// AND "+- " for search
	// TODO " "
	return preg_replace("/(([\w+\- ]*))/", '$0', $value);
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

// turns [a,b,c,d,e] into [{k:[a,b,c]},{k:[d,e]}], useful for templates
function util_array_chuck_into_object($array, $size, $key) {
	$tmp = array_chunk($array, 5);
	$object = array();
	foreach ($tmp as $item) {
		$object[] = array($key => $item);
	}
	return $object;
}

?>