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

	$queryOffset = 25 * $page;
	$data = _ld_fetch_page('node/feed/9405/parent+superparent/item/game?offset=' . $queryOffset . '&limit=25');
	$json = json_decode($data, true);
	
	foreach($json['feed'] as $node) {
    $entry_list[] = $node['id'];
	}

	return $entry_list;
}

/*
	Retrieves the author UID matching an entry UID

function ld_fetch_author_uid($uid) {
	$data = _ld_fetch_page('node/get/' . $uid);
	$json = json_decode($data, true);
	$entry_info = $json['node'][0];
	return $entry_info['author'];
}*/

/*
	Retrieves the full info for an entry
*/
function ld_fetch_entry($event_id, $uid) {
	/*static $PLATFORM_KEYWORDS = array(
		'windows' => ['windows', 'win32', 'win64', 'exe', 'java', 'jar'],
		'linux' => ['linux', 'debian', 'ubuntu', 'java', 'jar'],
		'osx' => ['mac', 'osx', 'os/x', 'os x', 'java', 'jar'],
		'android' => ['android', 'apk'],
		'web' => ['web', 'flash', 'swf', 'html', 'webgl', 'canvas', 'javascript'],
		'flash' => ['flash', 'swf'],
		'html5' => ['html', 'webgl', 'canvas', 'javascript'],
		'unity' => ['unity'],
		'vrgames' => ['vr', 'htc', 'vive', 'oculus', 'cardboard'],
		'htcvive' => ['htc', 'vive'],
		'oculus' => ['oculus'],
		'cardboard' => ['cardboard']
	);*/

	/* XXX More conservative platform detection until we actually have download links on ldjam.com */
	static $PLATFORM_KEYWORDS = array(
		'windows' => ['windows', 'win32', 'win64', ' java ', ' jar '],
		'linux' => ['linux', 'debian', 'ubuntu', ' java ', ' jar '],
		'osx' => [' mac ', 'osx', 'os/x', 'os x', ' java ', ' jar '],
		'android' => ['android', 'apk'],
		'web' => [' web ', 'swf', 'html', 'webgl', 'javascript'],
		'flash' => ['swf'],
		'html5' => ['html', 'webgl', 'javascript'],
		'unity' => ['unity'],
		'vrgames' => ['htc', 'oculus', 'cardboard'],
		'htcvive' => ['htc'],
		'oculus' => ['oculus'],
		'cardboard' => ['cardboard']
	);

	// Fetch entry & figure out platforms

	$data = _ld_fetch_page('node/get/' . $uid);
	$json = json_decode($data, true);
	$entry_info = $json['node'][0];

	$platforms = '';
	$platforms_text = strtolower($entry_info['body']);
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
	if ($platforms == '') {
		$platforms = 'unknown';
	}

	// Fetch comments

	$comment_data = _ld_fetch_page('note/get/' . $uid);
	$comment_json = json_decode($comment_data, true);
	$comment_info = $comment_json['note'];

  $Parsedown = new Parsedown();
	$comments = array();
	$order = 1;
	$authors_to_fetch = [$entry_info['author']];
	foreach ($comment_info as $comment) {
		if (!array_search($comment['author'], $authors_to_fetch)) {
			$authors_to_fetch[] = $comment['author'];
		}
		$comments[] = array(
			'uid_author' => $comment['author'],
			'author' => null, // Will be fetched below
			'order' => $order++,
			'comment' => strip_tags($Parsedown->text($comment['body'])),
			'date' => new DateTime($comment['created'])
		);
	}

	// Fetch authors (both for the entry & commenters)

	$authors_url = 'node/get/' . implode('+', $authors_to_fetch);
	$authors_data = _ld_fetch_page($authors_url);
	$authors_json = json_decode($authors_data, true);
	$authors_info = $authors_json['node'];

	foreach ($authors_info as $author_info) {
		if ($author_info['id'] == $entry_info['author']) {
			$author_uid = $author_info['id'];
			$author = $author_info['name'];
			$author_link = LD_WEB_ROOT . 'users/' . $author_info['path'];
		  $author_page = $author_info['slug'];
		}
		foreach ($comments as &$comment) {
			if ($author_info['id'] == $comment['uid_author']) {
				$comment['author'] = $author_info['name'];
			}
		}
	}
  
  // Locate first picture

  preg_match('/\!\[.*\]\((.*)\)/', $entry_info['body'], $pictures);
  if (count($pictures) > 1) {
	  $first_picture = str_replace('///', 'http://static.jam.vg/', $pictures[1]);
	} else {
		$first_picture = null;
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
    'description' => strip_tags($Parsedown->text($entry_info['body'])),
    'platforms' => $platforms,
    'picture' => $first_picture,
    'comments' => $comments
  );
  
  return $entry;
	
}

?>
