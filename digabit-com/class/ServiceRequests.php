<?php
/*
 * ServiceRequests
 *
 * (c) Digabit, 2015
 *
 * Author: steve.neill@digabit.com
 *
 */

class ServiceRequests
{
	private $serviceTools;

	public function __construct()
	{
		$this->tools = new ServiceTools();
	}

	private function requestOptions()
	{
		$request = explode('?', substr($_SERVER['REQUEST_URI'], 1));
		$params = array();
		parse_str($request[1], $params);

		return $params;
	}

	private function requestPath()
	{
		$request = explode('?', substr($_SERVER['REQUEST_URI'], 1));
		$parts = explode('/', $request[0]);
		array_shift($parts);

		return join('/', $parts);
	}

	public function deleteRequest()
	{
		return $this->requestPath();
	}

	public function getMethod()
	{
		return $_SERVER['REQUEST_METHOD'];
	}

	public function getRequest()
	{
		$request = $this->requestPath();
		$path = explode('/', $request);
		$params = $this->requestOptions();
		$filter = urldecode($params['filter']);
		$hash = urldecode($params['hash']);
		$conforms = intval($params['conforms']);
		$start = $params['start'];
		$limit = $params['limit'];
		$data = array();

		switch (TRUE)
		{
			// list folders within a migration
			case preg_match('/^migration\/\d+$/', $request):
				$migration = $this->tools->getMigrationById($path[1]);
				$data = array($migration);
				break;

			// list folders within a migration
			case preg_match('/^migration\/\d+\/folders$/', $request):
				if ($migration = $this->tools->getMigrationById($path[1]))
				{
					$options = array();
					if (!empty($filter))
					{
						$options['name'] = $filter;
					}

					if (isset($params['tree']))
					{
						$options['tree'] = true;
					}

					//$options['limit'] = 500;

					$data = $migration->getFolders($options);
					//print_r($data); exit;
					if (isset($params['tree']))
					{
						$data = $migration->generateTree($data, array('name' => 'path', 'expanded' => isset($params['expanded'])));

						//if (!empty($filter))
						//{
						//	$migration->filterTree($data, array('path', 'contains', $filter));
						//}
					}
				}
				break;

			// list migrations
			case preg_match('/^migrations$/', $request):
				$data = $this->tools->getMigrations();
				break;

			// view a specific file's paths
			case preg_match('/^migration\/\d+\/file\/\d+\/paths$/', $request):
				if ($migration = $this->tools->getMigrationById($path[1]))
				{
					$data = $migration->executePath($path[3], $filter);
				}
				break;

//			// attempt to map spatial layout to grid
//			case preg_match('/^migration\/\d+\/file\/\d+\/spatialgrid$/', $request):
//				if ($migration = $this->tools->getMigrationById($path[1]))
//				{
//					$data = $migration->spatialGrid($path[3]);
//				}
//				break;

			// view a specific file
			case preg_match('/^migration\/\d+\/file\/\d+\/view$/', $request):
				if ($migration = $this->tools->getMigrationById($path[1]))
				{
					return $migration->viewFile($path[3]);
				}
				break;

			// list files for a specific folder within a migration
			case preg_match('/^migration\/\d+\/folder\/\d+\/files$/', $request):
				if ($migration = $this->tools->getMigrationById($path[1]))
				{
					if ($hash)
					{
						$data = $migration->getPathFiles($path[3], $filter, $hash, $conforms, $start, $limit);
					}
					else
					{
						$data = $migration->getFiles($path[3], $filter, $start, $limit);
					}

					//if (isset($params['tree']))
					//{
					//	$data = $migration->generateTree($data, array('name' => 'folder', 'expanded' => isset($params['expanded'])));
					//}
				}
				break;

			// list paths for a specific folder within a migration
			case preg_match('/^migration\/\d+\/folder\/\d+\/paths$/', $request):
				if ($migration = $this->tools->getMigrationById($path[1]))
				{
					$data = $migration->getPaths($path[3]);

					if (isset($params['tree']))
					{
						foreach ($data as $item)
						{
							$item->iconCls = preg_match('/]$/', $item->path) ? 'x-tree-icon-attribute' : 'x-tree-icon-tag';
						}

						$data = $migration->generateTree($data, array('name' => 'path', 'expanded' => isset($params['expanded'])));
						$filters = array();

						if (!empty($filter))
						{
							$filters[] = array('path', 'contains', $filter);
						}

						if ($params['minconforms'])
						{
							$filters[] = array('conforms', 'ge', $params['minconforms']);
						}

						if ($params['maxconforms'])
						{
							$filters[] = array('conforms', 'le', $params['maxconforms']);
						}

						if (count($filters))
						{
							$migration->filterTree($data, $filters);
						}
					}
					else
					{
						if (!empty($filter))
						{
							$migration->filterData($data, 'path', $filter);
						}
					}
				}
				break;

			default:
				break;
		}

		return json_encode($data);
	}

	public function putRequest()
	{
		return $this->requestPath();
	}

}
?>
