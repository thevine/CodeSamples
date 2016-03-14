/*
 * File:
 * image.js
 * 
 * Dependencies:
 * PhantomJS
 * 
 * Description:
 * Create an image from an offset and size.
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

if (system.args.length !== 7) {
	system.stdout.writeLine("Usage: " + system.args[0] + " <html-file-path> <image-file-path> <top> <left> <height> <width>");
	phantom.exit(1);
}

var page = require('webpage').create();

page.viewportSize = {
	width: 1024,
	height: 768
};

page.clipRect = {
	top: system.args[3],
	left: system.args[4],
	height: system.args[5],
	width: system.args[6]
};

page.open(system.args[1], function() {
	page.render(system.args[2]);
	phantom.exit();
});
