'use strict';

window.api = (function() {

	var eventCache = {};

	function searchUsernames(eventId, query, callback) {
		var url = 'api.php?action=userid&event=' + encodeURIComponent(eventId) + '&query=' + encodeURIComponent(query);
		$.get(url,
			function(data, textStatus, jqXHR) {
				callback(data);
			}
		);
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
					entry.picture = 'data/' + encodeURIComponent(eventId) + '/' + encodeURIComponent(entry.uid) + '.jpg';
					// Platforms are sent in a single string to save on punctuation.
					entry.platforms = entry.platforms.toLowerCase().split(' ');
					// Format type

				}

				eventCache[eventId] = entries;
				$('#loader').hide();
				callback(eventCache[eventId]);
			});
		}
	}

	return {
		searchUsernames: searchUsernames,
		fetchEventSummary: fetchEventSummary
	};

})();
