<?php

function view_prepare_entry($entry) {
	global $event_id;

	if (isset($entry['type'])) {
		$entry['picture'] = util_get_picture_url($event_id, $entry['uid']);
		$entry['type_label'] = util_format_type($entry['type']);
		$entry['platforms_label'] = util_format_platforms($entry['platforms']);
	}

	return $entry;
}

?>