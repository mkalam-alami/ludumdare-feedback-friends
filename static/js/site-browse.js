(function() {

var LDFF_SCRAPING_ROOT = 'http://ludumdare.com/compo/';
var LDFF_ROOT_URL = '/';

var templates = {};
var eventCache = {};

$(window).load(function() {
	loadTemplates();
	bindSearch();
	pushHistory(window.location.href, $('#results').html());
	runSearch();
});

function loadTemplates() {
	templates.results = $('#results-template').html();
	templates.cartridge = $('#cartridge-template').html();
}

// AJAX/History support

function pushHistory(url, html) {
	window.history.pushState({
		"html": $('#results').html(),
		"search-platforms": $('#search-platforms').val(),
		"search-query": $('#search-query').val()
	}, "", url);
}

window.onpopstate = function (e) {
	if (e.state) {
		$('#search-platforms').val(e.state['search-platforms']);
		$('#search-query').val(e.state['search-query']);
		$('#search-platforms').multiselect('refresh');
		refreshResults();
	}
};

// Search form

function refreshEvent() {
	var value = $('#search-event').val();
	var label = $('#search-event-option-' + value).html();
	$('#search-event-label').html(label);
}

function refreshSorting() {
	var value = $('#search-sorting').val();
	$('.search-sorting-button').removeClass('active');
	$('#search-sorting-button-' + value).addClass('active');
}

function reset(eventId) {
	//$('#search-event').val(eventId || $('#search-event').attr('data-active-event'));
	$('#search-sorting').val('coolness');
	$('#search-platforms').val([]);
	$('#search-platforms').multiselect('refresh');
	$('#search-query').val('');
	//refreshEvent();
	refreshSorting();
	runSearch();
}

function bindSearch() {

	// Event
	refreshEvent();
	$('#search-event-dropdown a').click(function () {
		$('#search-event').val($(this).attr('data-value'));
		refreshEvent();
		runSearch();
	});

	// Username
	$('#username').typeahead({
		minLength: 2,
		highlight: true,
	}, {
		source: function(query, syncResults, asyncResults) {
			var eventId = $('#search-event').val();
			searchUsernames(eventId, query, asyncResults);
		},
		async: true,
		limit: 1e99, // Limiting happens server-side
		display: function(suggestion) {
			return suggestion.username;
		},
		templates: {
			pending: '<div class="username-loader" />',
			notFound: '<div class="username-not-found">Not found</div>',
		},
	});
	$('#username').bind('typeahead:select', function(e, selection) {
		$('#userid').val(selection.userid);
		runSearch();
	});
	$('#username').bind('typeahead:change', function(e, value) {
		if (!value) {
			$('#userid').val('');
			runSearch();
		}
	});

	// Sorting
	refreshSorting();
	$('.search-sorting-button').click(function () {
		$('#search-sorting').val($(this).attr('data-value'));
		refreshSorting();
		runSearch();
	});

	// Platforms
	$('#search-platforms').val($('#search-platforms-values').text().split(', '));
	$('#search-platforms').multiselect();
	$('#search-platforms').change(runSearch);

	// Query
	var lastKeyDown = 0;
	$('#search-query').keydown(function() { // Trigger search after .2s without a keypress
		var currentDate = new Date().getTime();
		lastKeyDown = currentDate;

		setTimeout(function() {
			if (lastKeyDown == currentDate) {
				runSearch();
			}
		}, 200);
	});

	// Reset
	$('#search-reset').bind("keypress click", function() {
		reset();
	});

	// Prevent keyboard submit
	$('#search').submit(function(e) {
		e.preventDefault();
	});
}

function searchUsernames(eventId, query, callback) {
	$.get(
		'../../userid.php?event=' + encodeURIComponent(eventId) + '&query=' + encodeURIComponent(query),
		function(data, textStatus, jqXHR) {
			callback(data);
		}
	);
}

function runSearch() {
	var eventId = $('#search-event').val();
	if (eventCache[eventId]) {
		refreshResults();
	} else {
		$('#loader').show();
		var url = 'eventsummary.php?event=' + encodeURIComponent(eventId);
		$.get(url, function(entries) {
			augmentEntries(eventId, entries);
			eventCache[eventId] = entries;
			refreshResults();
			$('#loader').hide();
		});
	}
}

function augmentEntries(eventId, entries) {
	for (var i = 0; i < entries.length; i++) {
		var entry = entries[i];
		// Picture URLs aren't sent from the server; that would be redundant and wasteful.
		entry.picture = createPictureUrl(eventId, entry.uid);
		// Platforms are sent in a single string to save on punctuation.
		entry.platforms = entry.platforms.toLowerCase().split(' ');
	}
}

function isUsersOwnEntry(userId, entry) {
	return entry.uid == userId;
}

function hasUserCommented(userId, entry) {
	return entry.commenter_ids.indexOf(userId) != -1;
}

function matchesPlatforms(platforms, entry) {
	for (var j = 0; j < platforms.length; j++) {
		if (entry.platforms.indexOf(platforms[j]) >= 0) {
			return true;
		}
	}
	return false;
}

// Returns a function that matches its argument against the given query.
function parseQuery(query) {
	var parts = query.toLowerCase().split(/\s+/);
	var allOf = [];
	var noneOf = [];
	var someOf = [];
	for (var i = 0; i < parts.length; i++) {
		var part = parts[i];
		if (!part) continue;
		var category;
		if (part[0] == '+') {
			part = part.substring(1);
			category = allOf;
		} else if (part[0] == '-') {
			part = part.substring(1);
			category = noneOf;
		} else {
			category = someOf;
		}
		var escaped = '\\b' + part.replace(/[\-\[\]\/\{\}\(\)\+\?\.\\\^\$\|]/g, "\\$&").replace(/\*/g, '\\S*') + '\\b';
		category.push(new RegExp(escaped, 'i'));
	}
	console.log('allOf:', allOf, 'noneOf:', noneOf, 'someOf:', someOf);
	return function matches(input) {
		for (var i = 0; i < allOf.length; i++) {
			if (!allOf[i].test(input)) {
				return false;
			}
		}
		for (var i = 0; i < noneOf.length; i++) {
			if (noneOf[i].test(input)) {
				return false;
			}
		}
		if (someOf.length > 0) {
			for (var i = 0; i < someOf.length; i++) {
				if (someOf[i].test(input)) {
					return true;
				}
			}
			return false;
		} else {
			return true;
		}
	};
}

function matchesSearchQuery(queryMatcher, entry) {
	return queryMatcher(entry.author) || queryMatcher(entry.title);
}

var comparators = {
	coolness: function(a, b) {
		if (a.coolness < b.coolness) return 1;
		if (a.coolness > b.coolness) return -1;
		if (a.last_updated < b.last_updated) return 1;
		if (a.last_updated > b.last_updated) return -1;
		return 0;
	},
	received: function(a, b) {
		if (a.comments_received < b.comments_received) return -1;
		if (a.comments_received > b.comments_received) return 1;
		if (a.comments_given < b.comments_given) return 1;
		if (a.comments_given > b.comments_given) return -1;
		if (a.last_updated < b.last_updated) return 1;
		if (a.last_updated > b.last_updated) return -1;
		return 0;
	},
	received_desc: function(a, b) {
		if (a.comments_received < b.comments_received) return 1;
		if (a.comments_received > b.comments_received) return -1;
		if (a.last_updated < b.last_updated) return 1;
		if (a.last_updated > b.last_updated) return -1;
		return 0;
	},
	given: function(a, b) {
		if (a.comments_given < b.comments_given) return 1;
		if (a.comments_given > b.comments_given) return -1;
		if (a.last_updated < b.last_updated) return 1;
		if (a.last_updated > b.last_updated) return -1;
		return 0;
	},
	laziest: function(a, b) {
		if (a.coolness < b.coolness) return -1;
		if (a.coolness > b.coolness) return 1;
		if (a.comments_given < b.comments_given) return -1;
		if (a.comments_given > b.comments_given) return 1;
		if (a.comments_received < b.comments_received) return -1;
		if (a.comments_received > b.comments_received) return 1;
		if (a.last_updated < b.last_updated) return 1;
		if (a.last_updated > b.last_updated) return -1;
		return 0;
	},
};

function shuffle(array) {
	var n = array.length;
	for (var i = 0; i < n; i++) {
		var j = i + Math.floor(Math.random() * (n - i));
		var tmp = array[i];
		array[i] = array[j];
		array[j] = tmp;
	}
}

function sortEntries(sorting, entries) {
	if (sorting == 'random') {
		// We can't simply return a random number from the comparator, because the
		// ordering will be ill-defined and dependent on the sort implementation.
		shuffle(entries);
	} else {
		var comparator = comparators[sorting] || comparators['coolness'];
		entries.sort(comparator);
	}
}

function refreshResults() {
	var eventId = $('#search-event').val();
	var entries = eventCache[eventId];
	var results = [];

	var userId = parseInt($('#userid').val()) || null;
	var sorting = $('#search-sorting').val();
	var platforms = $('#search-platforms').val();
	var query = $('#search-query').val();
	var queryMatcher = query ? parseQuery(query) : null;

	console.log('userId:', userId, 'sorting:', sorting, 'platforms:', platforms, 'query:', query);

	for (var i = 0; i < entries.length; i++) {
		var entry = entries[i];
		if (!platforms || matchesPlatforms(platforms, entry)) {
			if (queryMatcher) {
				if (matchesSearchQuery(queryMatcher, entry)) {
					results.push(entry);
				}
			} else {
				if (!userId || (!isUsersOwnEntry(userId, entry) && !hasUserCommented(userId, entry))) {
					results.push(entry);
				}
			}
		}
	}

	sortEntries(sorting, results);

	$('#results').html(formatResults(eventId, results));
	cartridgesStyling();
}

function createEventUrl(eventId) {
	return LDFF_SCRAPING_ROOT + encodeURIComponent(eventId) + '/?action=preview';
}

function createPictureUrl(eventId, uid) {
	return 'data/' + encodeURIComponent(eventId) + '/' + encodeURIComponent(uid) + '.jpg';
}

function formatResults(eventId, entries) {
	var context = {};
	context.root = LDFF_ROOT_URL;
	context.event_title = $('#search-event-option-' + eventId).text();
	context.event_url = createEventUrl(eventId);
	context.entries_only = false;
	context.entry_count = entries.length;
	context.are_entries_found = entries.length > 0;
	context.are_several_pages_found = true;
	context.entries = entries.slice(0, 9);
	return Mustache.render(templates.results, context, templates);
}

})();
