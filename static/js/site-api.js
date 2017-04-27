'use strict';

window.api = (function() {

	var eventCache = {};

	function readQueryParams() {
	 	var params = {};
		location.search.substr(1).split("&").forEach(function(item) {
		    var string = item.split("=");
		    var key = decodeURIComponent(string[0]);
		    if (!params[key]) {
		   		params[key] = decodeURIComponent(string[1]);
		    } else {
		   		params[key] += ',' + decodeURIComponent(string[1]);
		    }
		});
		return params;
	}

	function fetchUsername(userid, callback) {
		var url = 'api.php?action=username&userid=' + encodeURIComponent(userid);
		$.get(url, callback);
	}

	function searchUsernames(query, callback) {
		var url = 'api.php?action=userid&query=' + encodeURIComponent(query);
		$.get(url, callback);
	}

	function fetchEventSummary(eventId, callback) {
		if (eventCache[eventId]) {
			callback(eventCache[eventId]);
		} else {
			$('#loader').show();
			var url = 'api.php?action=eventsummary&event=' + encodeURIComponent(eventId);
			$.get(url, function(entries) {
				// Augment entries
				for (var i = 0; i < entries.length; i++) {
					var entry = entries[i];
					// Picture URLs aren't sent from the server; that would be redundant and wasteful.
					entry.picture = _createPictureUrl(encodeURIComponent(eventId), encodeURIComponent(entry.uid));
					// Platforms are sent in a single string to save on punctuation.
					entry.platforms = entry.platforms.toLowerCase().split(' ');
					// Format type (also implemented in PHP, see util_format_type)
					entry.type_label = _formatType(entry.type);
					// Format platforms (also implemented in JS, see util_format_platforms)
					entry.platforms_label = _formatPlatforms(entry.platforms);
				}

				eventCache[eventId] = entries;
				$('#loader').hide();
				callback(eventCache[eventId]);
			});
		}
	}

	function _createPictureUrl(eventId, uid) {
		return 'data/' + eventId + '/' + uid + '.jpg';
	}

	// Also implemented in PHP, see util_format_type()
	function _formatType(type) {
		return _capitalizeFirstLetter(type);
	}

	var PLATFORM_LABELS = {
		'osx': 'OSX',
		'html5': 'HTML5',
		'vrgames': 'VR',
		'htcvive': 'Vive'
	};

	// Also implemented in PHP, see util_format_platforms()
	function _formatPlatforms(platforms) {
		var result = '';
		platforms.forEach(function(platform) {
			if (PLATFORM_LABELS[platform]) {
				result += PLATFORM_LABELS[platform];
			}	else {
				result += _capitalizeFirstLetter(platform);
			}
			result += ' ';
		});
		return result;
	}

	function _capitalizeFirstLetter(text) {
		return text.charAt(0).toUpperCase() + text.substr(1);
	}

	return {
		readQueryParams: readQueryParams,
		fetchUsername: fetchUsername,
		searchUsernames: searchUsernames,
		fetchEventSummary: fetchEventSummary
	};

})();
