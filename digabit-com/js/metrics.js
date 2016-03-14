/*
 * File:
 * metrics.js
 * 
 * Dependencies:
 * PhantomJS
 * 
 * Description:
 * Parse HTML file and return a JSON structure
 * containing the flattened positions of all page
 * elements.
 * 
 * Author:
 * Steve Neill <steve.neill@digabit.com>
 * 
 */

var system = require('system');
var page = require('webpage').create();

page.onError = function(msg, trace) {
	console.log(msg);
	trace.forEach(function(item) {
		system.stdout.write(item.file + ':' + item.line + '\n');
	});
	phantom.exit(1);
};

if (system.args.length !== 2) {
	system.stdout.writeLine("Usage: " + system.args[0] + " <url>");
	phantom.exit(1);
}

page.open('file:///' + system.args[1], function(status) {
	if (status === 'success') {
		var nodes = page.evaluate(function() {
			var getAttrs = function(tree) {
				var attributes = {};
				var attrs = tree.attributes;

				if (attrs) {
					for (var i = 0; i < attrs.length; i++) {
						attributes[attrs[i].name] = attrs[i].value;
					}
				}

				return attributes;	
			};

			var getMetrics = function(tree) {
				var metrics = {
					value: null
				};
				var rect = tree.getBoundingClientRect();

				for (var p in rect) {
					metrics[p] = parseInt(rect[p]);
				}

				metrics['area'] = Math.ceil(metrics['height'] * metrics['width']);
				metrics['center'] = Math.ceil(metrics['left'] + (metrics['width'] / 2));
				metrics['orientation'] = metrics['height'] > metrics['width'] ? 'portrait' : 'landscape';
			
				return metrics;	
			};

			var parse = function(tree) {
				this.a = this.a || [];
				this.parents = this.parents || [0];

				var attrs;
				var parent = this.parents[this.parents.length-1];

				do {
					attrs = getAttrs(tree);

					if (tree.nodeType == 1) {
						this.a.push({
							type: 'tag',
							name: tree.tagName,
							parent: parent,
							attributes: attrs,
							metrics: getMetrics(tree)
						});
					}
					else if (tree.nodeType == 3) {
						switch (true) {
							// ignore lines with CRLF
							case tree.textContent.indexOf('\n') >= 0:
							
							// ignore empty text values
							case tree.textContent.replace(/\s/g, '') == '':
								break;

							default:
								this.a.push({
									type: 'text',
									parent: parent,
									value: tree.textContent
								});
								break;
						}
					}

					if (tree.hasChildNodes()) {
						if (attrs.id) {
							this.parents.push(attrs.id);
						}

						parse(tree.firstChild);

						if (attrs.id) {
							this.parents.pop();
						}
					}
				} while (tree = tree.nextSibling);

				return this.a;
			};

			return parse(document);
		});

		system.stdout.write(JSON.stringify(nodes));
	}
	else {
		system.stdout.write("Failed to load " + system.args[1]);
	}

	phantom.exit(0);
});


