(function() {

$(window).load(function() {
	bindSearch();
	entriesStyling();
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
	entriesStyling();
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

// Entries styling

function entriesStyling() {
	$('.entry').each(function(index, entry) {
		var entryImg = $('img', entry).get(0);
		if (entryImg) {
			if (entryImg.complete) {
				configureImageColor(entry, entryImg);
			}
			else {
				entryImg.addEventListener('load', function() {
					configureImageColor(entry, entryImg);
				});
			}
		}
	});
}

function configureImageColor(entry, entryImg) {
    var vibrant = new Vibrant(entryImg);
    var swatches = vibrant.swatches();
    if (swatches) {
    	var vibrantColor = swatches['Vibrant'] || swatches['DarkVibrant'] || swatches['LightVibrant'];
    	//var mutedColor = swatches['Muted'] || swatches['DarkMuted'] || swatches['LightMuted'] || vibrantColor;
    	
    	if (vibrantColor) {
    		$(entry).attr('style',
    			'background-color: ' + vibrantColor.getHex() + '; ' + 
    			'color: ' + vibrantColor.getBodyTextColor());
	    }
    	/*if (mutedColor) {
    		$('img', entry).attr('style',
    			'border: 1px solid ' + mutedColor.getHex());
	    }*/
	}
}

})();