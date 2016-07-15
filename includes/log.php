<?php

function log_info($message) {
	if (!LDFF_PRODUCTION) {
		return _log('INFO', $message);
	}
	else {
		return null;
	}
}

function log_warning($message) {
	if (!LDFF_PRODUCTION) {
		return _log('WARN', $message);
	}
	else {
		return null;
	}
}

function log_error($message) {
	$log_entry = _log('ERROR', $message);
	if (!LDFF_PRODUCTION) { // Die on first error except in production
		_log_print_stacktrace();
		die('<br/>'.$log_entry);
	}
	return $log_entry;
}

function log_error_and_die($public_message, $message) {
	log_error($public_message.': '.$message);
	die($public_message);
}

function _log($level, $message) {
	$log_entry = _log_format($level, $message);
	if (LDFF_LOG_ENABLED) {
		$success = file_put_contents(LDFF_LOG_PATH, $log_entry."\n", FILE_APPEND);
		if (!$success && !LDFF_PRODUCTION) {
			die('Failed to write in '.LDFF_LOG_PATH);
		}
	}
	return $log_entry;
}

function _log_format($level, $message) {
	$date = date('Y-m-d H:m:s');
	return "$level [$date] $message";
}

// Source: http://stackoverflow.com/a/32365961
function _log_print_stacktrace() {
    $stack = debug_backtrace();
    $output = '';

    $stackLen = count($stack);
    for ($i = 1; $i < $stackLen; $i++) {
        $entry = $stack[$i];

        $func = $entry['function'] . '(';
        $argsLen = count($entry['args']);
        for ($j = 0; $j < $argsLen; $j++) {
            $my_entry = $entry['args'][$j];
            if (is_string($my_entry)) {
                $func .= $my_entry;
            }
            if ($j < $argsLen - 1) $func .= ', ';
        }
        $func .= ')';

        $entry_file = 'NO_FILE';
        if (array_key_exists('file', $entry)) {
            $entry_file = $entry['file'];               
        }
        $entry_line = 'NO_LINE';
        if (array_key_exists('line', $entry)) {
            $entry_line = $entry['line'];
        }           
        $output .= $entry_file . ':' . $entry_line . ' - ' . $func . PHP_EOL;
    }
    echo $output;
}

?>