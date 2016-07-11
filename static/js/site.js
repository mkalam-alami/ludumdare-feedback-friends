(function() {

$(window).load(function() {
	bindSearch();
	entriesStyling();
	pushHistory(window.location.href, $('#results').html());
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
	refreshResults(e.state['html']);
	$('#search-platforms').val(e.state['search-platforms']);
	$('#search-query').val(e.state['search-query']);
	$('#search-platforms').multiselect('refresh');
};

function refreshResults(html) {
	$('#results').html(html);
	entriesStyling();
}

// Search form

function bindSearch() {
	$('#search-platforms').val($('#search-platforms-values').text().split(', '));
	$('#search-platforms').multiselect();
	$('#search-platforms').change(runSearch);

	$('#search-query').change(runSearch);

	$('#search-reset').bind("keypress click", function() {
		$('#search-platforms').val([]);
		$('#search-platforms').multiselect('refresh');
		$('#search-query').val('');
		runSearch();
	});

	$('#search').submit(function(e) {
		e.preventDefault();
	});
}

function runSearch() {
	var url = '?' + $('#search').serialize();
	$.get(url + '&ajax=results', function(html) {
		refreshResults(html);
		pushHistory(url);
	})
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
    	var mutedColor = swatches['Muted'] || swatches['DarkMuted'] || swatches['LightMuted'] || vibrantColor;
    	
    	if (vibrantColor) {
    		$(entry).attr('style',
    			'background-color: ' + vibrantColor.getHex() + '; ' + 
    			'color: ' + vibrantColor.getBodyTextColor());
	    }
    	if (mutedColor) {
    		/*$('img', entry).attr('style',
    			'border: 1px solid ' + mutedColor.getHex());*/
	    }
	}
}

})();