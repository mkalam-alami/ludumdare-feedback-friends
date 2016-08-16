'use strict';

(function() {

$(window).load(function() {
	// Detect own entry
	if (localStorage && api.readQueryParams().uid == localStorage.userid)
	$('.entry-overlay').each(function(index, el) {
		var $el = $(el);
		$el.attr('src', $el.attr('src').replace('.png', '_mine.png'));
	});
	
	// Color cartridge
	cartridgesStyling($('.entry'));
});

})();