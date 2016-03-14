/*
 * Simple news server for Elva web-developer application
 * 
 * Author: Steve Neill <steve@steveneill.com>
 * Date: 10th February, 2016
 * 
 */

// include modules
var http = require('http');
var mysql = require('mysql');

// db connection
var dbConn = mysql.createConnection({
	host: 'localhost',
	user: 'elva',
	password: '',
	database: 'elva',
	charset: 'utf8'
});

dbConn.connect();

console.log('news server started');

// new server
http.createServer(function(request, response)
{
	var articleData = {};
	var categoryData = [];
	var sql;
	var query;
	var data;

	// look for URLs we can handle
	switch (true)
	{
		// fetch news articles for a given locale
		case (match = request.url.match(/\/api\/get-news\/(en|ge)/)) !== null:
			sql = "SELECT FROM_UNIXTIME(`date`, '%Y/%m/%d') AS date, `title_{locale}` AS title, `text_{locale}` AS text FROM `article` ORDER BY `date` DESC".replace(/\{locale\}/g, match[1]);

			query = dbConn.query(sql, function(err, rows, fields) {
				if (err) throw err;

				data = '';
				for (var i in rows)
				{
					data += '<h2 class="title">' + rows[i].title + '</h2>';
					data += '<div class="date">' + rows[i].date + '</div>';
					data += '<div class="text">' + rows[i].text + '</div>';
				}

				response.writeHead(200, {
					'Content-Type': 'text/html',
					'Content-Length': data.length
				});
				response.write(data);
				response.end();
			});
			break;

		// submit a news article
		case request.url.match(/\/api\/submit-news/) !== null:
			// create an object from the POST data...
			request.on('data', function(data)
			{
				data = JSON.parse(data.toString());

				for (var name in data)
				{
					switch (name)
					{
						case 'date':
							// convert date to unix timestamp -- default to current time if necessary
					    	articleData[name] = (data[name] ? Date.parse(data[name]) : new Date()) / 1000;
					    	break;

						case 'title_en':
						case 'title_ge':
						case 'text_en':
						case 'text_ge':
							// string values
					    	articleData[name] = data[name];
					    	break;

					    case 'category':
					    	// category id array
					    	categoryData = data[name];
					    	break;

					    default:
					    	break;
					}
				}

				// create the article record
				sql = "INSERT INTO `article` SET ?";
				query = dbConn.query(sql, articleData, function(err, result) {
					if (err) throw err;

					for (var i = 0; i < categoryData.length; i++)
					{
						data = {
							'article_id': result.insertId,
							'category_id': categoryData[i]
						};

						// create the article category record/s
						sql = "INSERT INTO `article_category` SET ?";
						query = dbConn.query(sql, data, function(err, result) {
							if (err) throw err;
						});
					}

					_success(response);
				});
			});
			break;
		
		// we don't know about this url!
		default:
			_failure();
			break;
	}
}).listen(8080);

function _failure(response) {
	response.writeHead(500, {
		'Content-Type': 'text/plain'
	});
	response.end('failed');
}

function _success(response) {
	response.writeHead(200, {
		'Content-Type': 'text/plain'
	});
	response.end('success');
}
