(function() {

$(window).load(function() {
	bindSearch();
	pushHistory(window.location.href, $('#results').html());
	bindMore();
});

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
		refreshResults(e.state['html']);
		$('#search-platforms').val(e.state['search-platforms']);
		$('#search-query').val(e.state['search-query']);
		$('#search-platforms').multiselect('refresh');
	}
};

function refreshResults(html) {
	$('#results').html(html);
	bindMore();
	cartridgesStyling();
}

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
	$('#search-query').keydown(function() { // Trigger search after .5s without a keypress
		var currentDate = new Date().getTime();
		lastKeyDown = currentDate;

		setTimeout(function() {
			if (lastKeyDown == currentDate) {
				runSearch();
			}
		}, 500);
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
	$('#loader').show();
	var url = '?' + $('#search').serialize();
	$.get(url + '&ajax=results', function(html) {
		refreshResults(html);
		pushHistory(url);
		$('#loader').hide();
	})
}

// "Load more" button

function bindMore() {
	$('#more').click(function() {
		$('#more-container').remove();
		$('#loader').show();
		var nextPage = parseInt($(this).attr('data-page')) + 1;
		var url = '?' + $('#search').serialize();
		$.get(url + '&ajax=results&page=' + nextPage, function(html) {
			var oldHtml = $('#results').html();
			refreshResults(oldHtml + html);
			$('#loader').hide();
		})
	});
}

})();
