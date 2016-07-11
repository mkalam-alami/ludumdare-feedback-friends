(function() {

$(window).load(function() {
	bindSearch();
	configureEntries();
});


// Search form binding

function bindSearch() {
	$('#search-platforms').val($('#search-platforms-values').text().split(', '));
	$('#search-platforms').multiselect();
	$('#search-reset').bind("keypress click", function() {
		$('#search-platforms').val([]);
		$('#search-platforms').multiselect('refresh');
		$('#search-query').val('');
	});
}

// Entries dynamic CSS

function configureEntries() {
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