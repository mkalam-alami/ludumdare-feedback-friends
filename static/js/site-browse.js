'use strict';

(function() {

// These must match the CSS! Otherwise scrolling will have weird effects.
var ENTRY_WIDTH = 249;
var ENTRY_HEIGHT = 339;

// Number of offscreen rows to render, to make small scrolls feel more fluid.
var OFFSCREEN_ROWS = 3;

var templates = {};

var resultsVirtualScroll = null;

$(window).load(function() {
	loadTemplates();
	bindSearch();
	pushHistory(window.location.href);
	runSearch();
});

function loadTemplates() {
	['results', 'result', 'cartridge'].forEach(function(key) {
		var template = $('#' + key + '-template').html();
		if (!template) {
			throw new Error('Template "' + key + '" is missing');
		}
		try {
			Mustache.parse(template);
		} catch (ex) {
			throw new Error('Parse error in template "' + key + '": ' + ex);
		}
		templates[key] = template;
	});
}

// AJAX/History support

function readQueryParams() {
 	var params = {};
	location.search.substr(1).split("&").forEach(function(item) {
	    var string = item.split("=");
	    var key = decodeURIComponent(string[0]);
	    if (!params[key]) {
	   		params[key] = decodeURIComponent(string[1]);
	    }
	    else {
	   		params[key] += ',' + decodeURIComponent(string[1]);
	    }
	});
	return params;
}

function pushHistory(url) {
	if (window.location.search == '?' + $('#search').serialize()) {
		return;
	}
	window.history.pushState({
		"search-platforms": $('#search-platforms').val(),
		"search-sorting": $('#search-sorting').val(),
		"search-query": getSearchQuery(),
	}, "", url);
}

window.onpopstate = function (e) {
	if (e.state) {
		$('#search-platforms').val(e.state['search-platforms']);
		$('#search-sorting').val(e.state['search-sorting']);
		$('#search-query').val(e.state['search-query']);
		$('#search-platforms').multiselect('refresh');
		refreshSorting();
		runSearch();
	}
};

// Search form

function getEventId() {
	return $('#search-event').val();
}

function getSearchQuery() {
	return $('#search-query').val();
}

function refreshEvent() {
	var value = getEventId();
	var label = $('#search-event-option-' + value).html();
	$('#search-event-label').html(label);
}

function refreshSorting() {
	var value = $('#search-sorting').val();
	$('.search-sorting-button').removeClass('active');
	$('#search-sorting-button-' + value).addClass('active');
}

function reset(eventId) {
	$('#search-sorting').val('coolness');
	$('#search-platforms').val([]);
	$('#search-platforms').multiselect('refresh');
	$('#search-query').val('');
	refreshSorting();
	runSearch();
}

function bindSearch() {

	// Read query params
	var params = readQueryParams();
	$('#userid').val(params.userid);
	$('#search-event').val(params.event || config.LDFF_ACTIVE_EVENT_ID);
	$('#search-sorting').val(params.sorting || 'coolness');
	$('#search-query').val(params.query);
	$('#search-platforms').val(params.platforms ? params.platforms.split(',') : '');

	// Event
	refreshEvent();
	$('#search-event-dropdown a').click(function () {
		$('#search-event').val($(this).attr('data-value'));
		refreshEvent();
		runSearch();
	});

	// Init username
	if (params.userid) {
		api.fetchUsername(params.userid, function(data) {
			$('#username').val(data.author);
		});
	}

	// Username
	$('#username').typeahead({
		minLength: 2,
		highlight: true,
	}, {
		source: function(query, syncResults, asyncResults) {
			api.searchUsernames(query, asyncResults);
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
	$('.search-sorting-button').click(function(e) {
		$('#search-sorting').val($(this).attr('data-value'));
		refreshSorting();
		runSearch();
		e.preventDefault();
	});

	// Platforms
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


function runSearch() {
	var url = '?' + $('#search').serialize();
	pushHistory(url);

	var eventId = getEventId();
	api.fetchEventSummary(eventId, refreshResults);
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
	// console.log('allOf:', allOf, 'noneOf:', noneOf, 'someOf:', someOf);
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

function refreshResults(entries) {
	var eventId = getEventId();
	var results = [];

	var userId = parseInt($('#userid').val()) || null;
	var sorting = $('#search-sorting').val();
	var platforms = $('#search-platforms').val();
	var query = getSearchQuery();
	var queryMatcher = query ? parseQuery(query) : null;

	// console.log('userId:', userId, 'sorting:', sorting, 'platforms:', platforms, 'query:', query);

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

	renderResults(results);
}

function renderResults(results) {
	if (resultsVirtualScroll) {
		resultsVirtualScroll.unbind();
		resultsVirtualScroll = null;
	}

	var eventId = getEventId();

	var context = {};
	context.root = config.LDFF_ROOT_URL;
	context.event_title = $('#search-event-option-' + eventId).text();
	context.event_url = createEventUrl(eventId);
	context.title = (eventId != config.LDFF_ACTIVE_EVENT_ID || getSearchQuery()) ? 'Search results' : 'These entries need feedback!';
	context.entry_count = results.length;
	$('#results').html(Mustache.render(templates.results, context, templates));

	resultsVirtualScroll = createVirtualScroll($('#results-virtual-scroll'), results, renderEntry.bind(null, eventId), 'result-');
}

function createVirtualScroll(container, items, renderFunction, idPrefix) {
	container.css({'position': 'relative'});

	var prevWidth = -1;
	var startIndex = 0;
	var endIndex = 0;

	function renderVisibleResults() {
		var width = container.innerWidth();
		var numColumns = Math.max(1, Math.floor(width / ENTRY_WIDTH));
		var numRows = Math.ceil(items.length / numColumns);
		var columnWidth = width / numColumns;
		var xOffset = (columnWidth - ENTRY_WIDTH) / 2;

		if (width != prevWidth) {
			// Column count may need to change. Just start afresh. It's simplest.
			container.empty();
			container.innerHeight(numRows * ENTRY_HEIGHT);
			prevWidth = width;
		}

		var topVisible = window.scrollY - container.offset().top;
		// innerHeight includes any horizontal scrollbar, but that doesn't really matter.
		var bottomVisible = topVisible + window.innerHeight;
		var startRow = Math.floor(topVisible / ENTRY_HEIGHT) - OFFSCREEN_ROWS;
		var endRow = Math.ceil(bottomVisible / ENTRY_HEIGHT) + OFFSCREEN_ROWS;
		var newStartIndex = Math.max(0, numColumns * startRow);
		var newEndIndex = Math.min(items.length, numColumns * endRow);

		// Remove old entries.
		for (var index = startIndex; index < endIndex; index++) {
			if (index < newStartIndex || index >= newEndIndex) {
				// console.log('-' + index);
				$('#result-' + index).remove();
			}
		}

		// Add new entries.
		startIndex = newStartIndex;
		endIndex = newEndIndex;
		for (var index = startIndex; index < endIndex; index++) {
			if ($('#' + idPrefix + index).length > 0) {
				continue;
			}
			var child = renderFunction(items[index]);
			var row = Math.floor(index / numColumns);
			var column = index % numColumns;
			child.css({left: xOffset + column * columnWidth, top: row * ENTRY_HEIGHT});
			child.attr('id', idPrefix + index);
			// console.log('+' + index);
			container.append(child);
		}
	}

	var debounceTimer = null;
	function renderVisibleResultsDebounced() {
		if (!debounceTimer) {
			debounceTimer = window.setTimeout(function() {
				renderVisibleResults();
				debounceTimer = null;
			}, 200);
		}
	}

	$(window).bind('scroll resize', renderVisibleResultsDebounced);

	renderVisibleResults();

	return {
		unbind: function() {
			$(window).unbind('scroll resize', renderVisibleResultsDebounced);
		},
	};
}

function createEventUrl(eventId) {
	return config.LDFF_SCRAPING_ROOT + encodeURIComponent(eventId) + '/?action=preview';
}

function renderEntry(eventId, entry) {
	var context = {
		entry: entry,
		event_id: eventId,
		event_url: createEventUrl(eventId),
		root: config.LDFF_ROOT_URL,
	};
	var elt = $(Mustache.render(templates.result, context, templates));
	cartridgesStyling(elt.find('.entry'));
	return elt;
}

})();
