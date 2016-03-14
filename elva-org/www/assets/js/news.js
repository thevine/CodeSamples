/*
 * Simple news class for Elva web-developer application
 * 
 * Author: Steve Neill <steve@steveneill.com>
 * Date: 10th February, 2016
 * 
 */

// create a news class
var news = (function() {
	// public interface
	return {
		// public method
		load : function(locale) {
			// prevent a repeat load of the same data
			if (this.locale == locale) return;
			this.locale = locale;
			
			// load the news
			$.ajax({
				type : 'GET',
				url : '/api/get-news/' + locale,
				cache : false,
				success : function(data) {
					$('#content').html(data);
				}
			});

			// update the locale button style
			$('div#title a').toggleClass('selected');
		}
	}
})();

// initialize the UI
$(function() {
	// initialize the page
	$('#ge_news').addClass('selected');
	news.load('en');

	// set up the locale switching actions
	$('#en_news').click(function() {
		news.load('en');
	});

	$('#ge_news').click(function() {
		news.load('ge');
	});
});
