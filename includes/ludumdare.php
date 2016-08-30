<?php

function _ld_fetch_page($event_id, $queryParams) {
	$url = LDFF_SCRAPING_ROOT . $event_id . '/?' . $queryParams;
	return file_get_contents($url);
}

/*
	Retrieves UIDs from all event entries.
*/
function ld_fetch_uids($event_id) {
	$entry_list = array();

	$data = _ld_fetch_page($event_id, 'action=misc_links');
	phpQuery::newDocumentHTML($data);

	foreach(pq('#compo2 td > a') as $entry_el) {
		$entry_list[] = str_replace('?action=preview&uid=', '', pq($entry_el)->attr('href'));
	}

	return $entry_list;
}

/*
	Retrieves the full info for an entry
*/
function ld_fetch_entry($event_id, $uid) {
	static $PLATFORM_KEYWORDS = array(
		'windows' => ['windows', 'win32', 'win64', 'exe', 'java', 'jar'],
		'linux' => ['linux', 'debian', 'ubuntu', 'java', 'jar'],
		'osx' => ['mac', 'osx', 'os/x', 'os x', 'java', 'jar'],
		'android' => ['android', 'apk'],
		'web' => ['web', 'flash', 'swf', 'html', 'webgl', 'canvas'],
		'flash' => ['flash', 'swf'],
		'html5' => ['html', 'webgl', 'canvas'],
		'unity' => ['unity'],
		'vrgames' => ['vr', 'vive', 'oculus', 'cardboard'],
		'htcvive' => ['vive'],
		'oculus' => ['oculus'],
		'cardboard' => ['cardboard']
	);

	// Fetch page and remove <script> tags for phpQuery (http://stackoverflow.com/a/36912417)
	$html = _ld_fetch_page($event_id, 'action=preview&uid=' . $uid);
 	preg_match_all('/<script.*?>[\s\S]*?<\/script>/', $html, $tmp);
    $scripts_array = $tmp[0]; 
    foreach ($scripts_array as $script_id => $script_item){
        $html = str_replace($script_item, '', $html);
    }
	phpQuery::newDocumentHTML($html);

	// Grab title to make sure we're on a working entry page
	$title = pq('#compo2 h2')->eq(0)->text();

	if ($title) {

		// Figure out platforms
		$platforms = '';
		$platforms_text = strtolower(pq('.links li')->text());
		foreach ($PLATFORM_KEYWORDS as $platform_name => $keywords) {
			$found = false;
			foreach ($keywords as $keyword) {
				if (strpos($platforms_text, $keyword) !== false) {
					$found = true;
					break;
				}
			}

			if ($found) {
				if ($platforms != '') {
					$platforms .= ' ';
				}
				$platforms .= $platform_name;
			}
		}

		// Special case: if there's an embed, it's a web entry
		if (strpos($platforms, 'web') === false && pq('.embed-controls')->size() > 0) {
			if ($platforms != '') {
				$platforms .= ' ';
			}
			$platforms .= 'web';
		}

		// Special case: unknown platform
		if ($platforms == '') {
			$platforms = 'unknown';
		}

		// Gather comments
		$comments = array();
		$order = 1;
		foreach (pq('.comment') as $comment) {
			$comments[] = array(
				'uid_author' => intval(str_replace('?action=preview&uid=', '', pq('a', $comment)->attr('href'))),
				'author' => utf8_decode(pq('a', $comment)->text()),
				'order' => $order++,
				'comment' => utf8_decode(pq('p', $comment)->html()),
				'date' => date_create_from_format('M d, Y @ g:ia', pq('small', $comment)->text())
			);
		}

		$author_link = pq('#compo2 a strong');

		// Build entry array
		$entry = array(
			'uid' => $uid,
			'author' => utf8_decode($author_link->text()),
			'author_page' => utf8_decode(preg_replace('/..\/author\/(.*)\//i', '$1', $author_link->parent()->attr('href'))),
			'title' => utf8_decode(pq('#compo2 h2')->eq(0)->text()),
			'type' => (pq('#compo2 > div > i')->text() == 'Competition Entry') ? 'compo' : 'jam',
			'description' => utf8_decode(pq(pq('#compo2 h2')->eq(1))->prev()->html()),
			'platforms' => $platforms,
			'picture' => pq('.shot-nav img')->attr('src'),
			'comments' => $comments
		);
		return $entry;

	}
	else {
		return null;
	}
	
}

?>
