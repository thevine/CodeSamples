//create a bind() function for scope passing
Function.prototype.bind = Function.prototype.bind ||
function() {
	var fn = this;
	var args = [].slice.call(arguments);
	var context = args.shift();

	return function() {
		return fn.apply(context, args.concat([].slice.call(arguments)));
	};
};

Function.prototype.bindAppend = function(context) {
	var fn = this;
	var args = [].slice.call(arguments).slice(1);

	return function() {
		return fn.apply(context, [].slice.call(arguments).concat(args));
	};
};

Number.prototype.within = function(min, max) {
	return this >= min && this <= max;
};

// replace string placeholders with values
// "this is a value of {1}".apply(100) ==> "this is a value of 100"
String.prototype.apply = function() {
	var a = arguments;

	return this.replace(/\{(\d+)\}/g, function(m, i) {
		return a[i - 1];
	});
};

String.prototype.ucfirst = function() {
	return this.charAt(0).toUpperCase() + this.slice(1);
};

