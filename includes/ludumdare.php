<?php

function _ld_fetch_page($path) {
	$url = LD_SCRAPING_ROOT . $path;
	return file_get_contents($url);
}

/*
	Retrieves UIDs from all event entries.
*/
function ld_fetch_uids($event_id, $page = 0) {
	$entry_list = array();

	$query_offset = 25 * $page;
	$data = _ld_fetch_page('node/feed/9405/parent+superparent/item/game?offset=' . $query_offset . '&limit=25');
	$json = json_decode($data, true);
	
	foreach($json['feed'] as $node) {
    $entry_list[] = $node['id'];
	}

	return $entry_list;
}

function _ld_is_picture_url($url) {
	$lower_case_url = strtolower($url);
	if (!strpos($url, 'youtube')) {
		foreach (array('jpg', 'gif', 'png') as $format) {
			if (substr($lower_case_url, -strlen($format)) === $format) {
				return true;
			}
		}
	}
	return false;
}

/*
	Retrieves the full info for an entry
	uid_author = Optionally, the author UID if known, for performance optimization
  author_cache = Optionally, a map of cached user names (key = ID, value = name), for performance optimization
*/
function ld_fetch_entry($event_id, $uid, $uid_author = null, $author_cache = []) {
	/*static $PLATFORM_KEYWORDS = array(
		'windows' => ['windows', 'win32', 'win64', 'exe', 'java', 'jar'],
		'linux' => ['linux', 'debian', 'ubuntu', 'java', 'jar'],
		'osx' => ['mac', 'osx', 'os/x', 'os x', 'java', 'jar'],
		'android' => ['android', 'apk'],
		'web' => ['web', 'flash', 'swf', 'html', 'webgl', 'canvas', 'javascript'],
		'flash' => ['flash', 'swf'],
		'html5' => ['html', 'webgl', 'canvas', 'javascript'],
		'unity' => ['unity'],
		'vrgam
		es' => ['vr', 'htc', 'vive', 'oculus', 'cardboard'],
		'htcvive' => ['htc', 'vive'],
		'oculus' => ['oculus'],
		'cardboard' => ['cardboard']
	);*/

	/* XXX More conservative platform detection until we actually have download links on ldjam.com */
	static $PLATFORM_KEYWORDS = array(
		'windows' => ['windows', 'win32', 'win64', ' java ', ' jar '],
		'linux' => ['linux', 'debian', 'ubuntu', ' java ', ' jar '],
		'osx' => [' mac ', ' mac:', 'osx', 'os/x', 'os x', ' java ', ' jar '],
		'android' => ['android', 'apk'],
		'web' => [' web ', '(web ', 'swf', 'html', 'webgl', 'javascript'],
		'flash' => ['swf'],
		'html5' => ['html', 'webgl', 'javascript'],
		'unity' => ['unity'],
		'vrgames' => ['htc', 'oculus', 'cardboard'],
		'htcvive' => ['htc'],
		'oculus' => ['oculus'],
		'cardboard' => ['cardboard']
	);

	// Fetch entry, combine with author if possible

	$data = _ld_fetch_page('node/get/' . $uid . (($uid_author) ? '+' . $uid_author : ''));
	$json = json_decode($data, true);
	$first_node = $json['node'][0];
	$optional_second_node = (isset($json['node'][1])) ? $json['node'][1] : null;
	if ($first_node['type'] == 'user') {
		$entry_info = $optional_second_node;
		$entry_author_info = $first_node;
	} else {
		$entry_info = $first_node;
		$entry_author_info = $optional_second_node;
	}
	if ($entry_info['subtype'] != 'game') {
		log_warning("Tried to fetch a [".$entry_info['type']."/".$entry_info['subtype']."] as a game");
		return null;
	}

	// Guess game platforms from description

  $Parsedown = new Parsedown();
	$description = preg_replace('/[\t\r\n]+/', ' ', strtolower(strip_tags($Parsedown->text($entry_info['body']))));
	$platforms = '';
	foreach ($PLATFORM_KEYWORDS as $platform_name => $keywords) {
		$found = false;
		foreach ($keywords as $keyword) {
			if (strpos($description, $keyword) !== false) {
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
	if ($platforms == '') {
		$platforms = 'unknown';
	}

	// Fetch comments

	$comment_data = _ld_fetch_page('note/get/' . $uid);
	$comment_json = json_decode($comment_data, true);
	$comment_info = $comment_json['note'];

	$comments = array();
	$order = 1;
	$authors_to_fetch = [];
	if (!$entry_author_info) {
		$authors_to_fetch[] = $entry_info['author'];
	}
	foreach ($comment_info as $comment) {
		if (isset($author_cache[$comment['author']])) {
			$cached_author = $author_cache[$comment['author']];
		} else {
			$cached_author = null;
			if (!array_search($comment['author'], $authors_to_fetch)) {
				$authors_to_fetch[] = $comment['author'];
			}
		}
		$comments[] = array(
			'uid_author' => $comment['author'],
			'author' => $cached_author,
			'order' => $order++,
			'comment' => strip_tags($Parsedown->text($comment['body'])),
			'date' => new DateTime($comment['created'])
		);
	}

	// If needed, fetch author + commenters info
  //log_info("Fetching " . count($authors_to_fetch) . " authors for entry $uid (comment count: " . count($comments) . ")");
	if (count($authors_to_fetch) > 0) {
		$authors_url = 'node/get/' . implode('+', $authors_to_fetch);
		$authors_data = _ld_fetch_page($authors_url);
		$authors_json = json_decode($authors_data, true);
		$authors_info = $authors_json['node'];

		foreach ($authors_info as $author_info) {
			if ($author_info['id'] == $entry_info['author']) {
				$entry_author_info = $author_info;
			}
			foreach ($comments as &$comment) {
				if ($author_info['id'] == $comment['uid_author']) {
					$comment['author'] = $author_info['name'];
				}
			}
		}
  }

  // Set author info

	$author_uid = $entry_author_info['id'];
	$author = $entry_author_info['name'];
	$author_link = LD_WEB_ROOT . 'users/' . $entry_author_info['path'];
  $author_page = $entry_author_info['slug'];

  // Locate first picture

  preg_match_all('/\!\[[^]]*\]\(([^)]*)\)/', $entry_info['body'], $body_urls_match);
  $first_picture = null;
  foreach ($body_urls_match[1] as $body_url) {
  	if (_ld_is_picture_url($body_url)) {
  		$first_picture = str_replace('///', 'http://static.jam.vg/', $body_url);
  		log_info('Picture detection info for LDJam: '.$uid.','.$body_url);
  		break;
  	}
  }
  
	// Build entry array

	$entry = array(
    'uid' => $uid,
    'uid_author' => $author_uid,
    'author' => $author,
    'author_page' => $author_page,
    'entry_page' => str_replace('//', '/', LDFF_ACTIVE_EVENT_PATH . $entry_info['slug']),
    'title' => $entry_info['name'],
    'type' => ($entry_info['subsubtype'] == 'jam') ? 'jam' : 'compo',
    'description' => $description,
    'platforms' => $platforms,
    'picture' => $first_picture,
    'comments' => $comments
  );
  
  return $entry;
	
}

?>
