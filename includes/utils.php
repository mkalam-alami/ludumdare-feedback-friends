<?php

function util_query_param($key) {
	return preg_replace("/\W|_/", '', $_GET[$key]); // only keep alpha-numeric chars
}

?>