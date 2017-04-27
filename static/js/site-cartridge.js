'use strict';

window.cartridgesStyling = (function() {

	function cartridgesStyling(entries) {
		entries.each(function(index, entry) {
			var entryImg = $('img', entry).get(0);
			if (entryImg) {
				if (entryImg.complete && entryImg.naturalWidth > 0) {
					_configureImageColor(entry, entryImg);
				} else {
					entryImg.addEventListener('load', function() {
						_configureImageColor(entry, entryImg);
					});
				}
			}
		});
	}

	function _configureImageColor(entry, entryImg) {
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
	
	return cartridgesStyling;

})();
