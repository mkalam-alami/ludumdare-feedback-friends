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
	$data = _ld_fetch_page('node/feed/'.$event_id.'/smart+parent/item/game/compo+jam?offset=' . $query_offset . '&limit=25');
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
    
  /*
  (Codes can be found by checking the link dropdown's HTML)
  42336 HTML5
  42337 Windows
  42339 macOS
  42341 Linux
  42342 Android
  42346 iOS
  42349 Java
  42438 Flash
  42440 Web
  42332 Sources
  */
    
	static $PLATFORM_KEYWORDS = array(
		'windows' => [42349, 42337, ' java ', ' jar '],
		'linux' => [42349, 42341, ' java ', ' jar '],
		'osx' => [42349, 42339, ' java ', ' jar '],
		'android' => [42342],
		'web' => [42336, 42440, 42438],
		'flash' => [42438],
		'html5' => [42336],
		'unity' => ['unity'],
		'vrgames' => ['htc', 'oculus', 'google cardboard', ' vr '],
    'ios' => [42346],
    'board' => ['cards ', 'card ', 'board ']
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

  $platform_tags = [];
  foreach ($entry_info['meta'] as $meta_key => $meta_value) {
    if (strpos($meta_key, 'link') === 0 && strpos($meta_key, 'tag') === 8) {
      $platform_tags[] =  $meta_value;
    }
  }
  
  $Parsedown = new Parsedown();
	$description = preg_replace('/[\t\r\n]+/', ' ', strtolower(strip_tags($Parsedown->text($entry_info['body']))));
	$platforms = '';
	foreach ($PLATFORM_KEYWORDS as $platform_name => $keywords) {
		$found = false;
    foreach ($keywords as $keyword) {
      if (gettype($keyword) === 'integer' && array_search($keyword, $platform_tags) !== false
          || gettype($keyword) === 'string' && strpos($description, $keyword) !== false) {
        $found = true;
        break;
      }
    }

		if ($found) {
			if ($platforms != '') {
				$platforms .= ' ';
			}
      if ($platform_name != 'board' || $platforms == '') {
        $platforms .= $platform_name;
      }
		}
	}
	if ($platforms == '' || $platforms == 'src') {
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
			if (!array_search($comment['author'], $authors_to_fetch) && $comment['author'] != 0) {
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
    $authors_to_fetch_chunks = array_chunk($authors_to_fetch, 20);
    foreach ($authors_to_fetch_chunks as $authors_to_fetch_chunk) {
      $authors_url = 'node/get/' . implode('+', $authors_to_fetch_chunk);
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
  }

  // Set author info

	$author_uid = $entry_author_info['id'];
	$author = $entry_author_info['name'];
	$author_link = LD_WEB_ROOT . 'users/' . $entry_author_info['path'];
  $author_page = $entry_author_info['slug'];

  // Set cover picture, or by default choose first picture from post body

  $picture = $entry_info['meta']['cover'];
  if (!$picture) {
	  preg_match_all('/\!\[[^]]*\]\(([^)]*)\)/', $entry_info['body'], $body_urls_match);
	  foreach ($body_urls_match[1] as $body_url) {
	  	if (_ld_is_picture_url($body_url)) {
	  		$picture = $body_url;
	  		break;
	  	}
	  }
	}
	if ($picture) {
	  $picture = str_replace('///', 'http://static.jam.vg/', $picture);
	}
  
	// Build entry array

	$entry = array(
    'uid' => $uid,
    'uid_author' => $author_uid,
    'author' => $author,
    'author_page' => $author_page,
    'entry_page' => str_replace('//', '/', LDFF_ACTIVE_EVENT_PATH . $entry_info['slug']),
    'title' => $entry_info['name'],
    'type' => $entry_info['subsubtype'],
    'description' => $description,
    'platforms' => $platforms,
    'picture' => $picture,
    'balance' => $entry_info['magic']['smart'],
    'comments' => $comments
  );
  
  return $entry;
	
}

?>
