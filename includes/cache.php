<?php

function cache_read($key) {
	if (LDFF_PRODUCTION) {
		return apcu_fetch($key);
	}
	else {
		return null;
	}
}

function cache_write($key, $value) {
	apcu_store($key, $value, LDFF_CACHING_TTL);
}

function cache_delete($key) {
	apcu_delete($key);
}

// Caching: mock APCu functions if missing

if (!function_exists('apcu_fetch')) {
	
	function apcu_fetch($key) {
		return null;
	}
	function apcu_store($key, $value, $ttl = 0) {
		// Do nothing
	}
	function apcu_inc($key, $step = 1) {
		// Do nothing
	}
	function apcu_delete($key) {
		// Do nothing
	}
	function apcu_cache_info($limited = false) {
		return array(
			'cache_disabled' => true
		);
	}
	function apcu_clear_cache() {
		// Do nothing
	}

}

?>