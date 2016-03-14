/*
 * Simple admin class for Elva web-developer application
 * 
 * Author: Steve Neill <steve@steveneill.com>
 * Date: 10th February, 2016
 * 
 */

var admin = (function() {
	// private function
	var _thanks = function() {
		alert("Thank you!");
	};

	// public interface
	return {
		// public method
		addNews : function(form) {
			var data = form.serializeArray();
			var json = {
				category: []
			};

			$.each(data, function() {
				if (this.name == 'category') {
					json[this.name].push(this.value);
				}
				else {
					json[this.name] = this.value || '';
				}
			});

			$.ajax({
				type : 'POST',
				url : form.attr('action'),
				contentType : 'application/json',
				encoding : 'UTF-8',
				dataType : 'json',
				data : JSON.stringify(json),
				cache : false,
				complete : _thanks()
			});
		}
	}
})();

// initialize the UI
$(function() {
	// date picker handler
	$('#datepicker').datepicker();

	// form submit handler
	$('#form_article').submit(function(evt) {
		// prevent default form submit action
		evt.preventDefault();
		
		// add the new article
		admin.addNews($(this));
	});
});
