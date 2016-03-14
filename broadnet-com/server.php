<?php

function __autoload($name)
{
	include '/var/websites/disasterx-com/broadnet/api/class.' . strtolower($name) . '.php';	
}

$manager = new TaskManager();

// parse the URI parts
// A URI looks like this: /broadnet/api/task/<verb>[/<id>][?title=<string>]
$request = explode('?', $_SERVER['REQUEST_URI']);
$paths = split('&', $request[1]);
$apiPath = join('/', array_slice(split('/', $paths[0]), 2));

// some api methods don't have parameters -- handle this correctly
$params = array();
if (count($paths) > 1) {
	parse_str($paths[1], $params);
}

// task ids are preg_match'ed into here
$ids = array();

switch (TRUE)
{
	// CREATE action
	case preg_match('/^task\/add$/', $apiPath):
		$task = new Task();
		$task->title = urldecode($params['title']);
		$manager->addTask($task);
		$data = (string)$task;
		break;

	// READ action
	case preg_match('/^tasks$/', $apiPath):
		$data = $manager->getTasks();
		break;

	// UPDATE action
	case preg_match('/^task\/update\/(\d+)$/', $apiPath, $ids):
		$task = $manager->getTask($ids[1]);
		$task->title = urldecode($params['title']);
		$manager->updateTask($task);
		$data = json_encode(array());
		break;

	// DELETE action
	case preg_match('/^task\/delete\/(\d+)$/', $apiPath, $ids):
		$manager->deleteTask($ids[1]);
		$data = json_encode(array());
		break;
		
	// unrecognized action
	default:
		$data = json_encode(array());
		break;		
}

print $data;

