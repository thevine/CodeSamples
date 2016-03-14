<?php
/*
 * ServiceMigration
 *
 * (c) Digabit, 2015
 *
 * Author: steve.neill@digabit.com
 *
 */

define('STAGE_STARTED', 0);
define('STAGE_REGISTER_FOLDERS', 1);
define('STAGE_REGISTER_FILES', 2);
define('STAGE_PARSE_FILES', 3);
define('STAGE_COMPLETE', 4);
define('PERCENT_STARTED', 0);
define('PERCENT_COMPLETE', 100);
define('BATCH_INSERT_SIZE', 1000);

class ServiceMigration
{
	public $db;
	private $tools;

	public function __construct()
	{
		$this->tools = new ServiceTools();
		$this->db = $this->tools->getDb();
	}

	public function filterData(&$data, $field, $pattern)
	{
		$pattern = preg_quote($pattern, '/');

		foreach ($data as &$item)
		{
			if (preg_match("/$pattern/i", $item->$field) == 0)
			{
				$item = FALSE;
			}
		}

		$data = array_values(array_filter($data));
	}

	public function filterTree(&$tree, $filters)
	{
		foreach ($tree as &$item)
		{
			$match = TRUE;
			foreach ($filters as $filter)
			{
				$value = $item->$filter[0];

				switch ($filter[1])
				{
					case 'contains':
						$match &= preg_match('/' . preg_quote($filter[2], '/') . '/i', $value);
						break;

					case 'ends':
						$match &= preg_match('/' . preg_quote($filter[2], '$/') . '/i', $value);
						break;

					case 'begins':
						$match &= preg_match('/^' . preg_quote($filter[2], '/') . '/i', $value);
						break;

					case 'le':
						// less than or equal to
						$match &= $value <= $filter[2];
						break;

					case 'lt':
						// less than
						$match &= $value < $filter[2];
						break;

					case 'ge':
						// greater than or equal to
						$match &= $value >= $filter[2];
						break;

					case 'gt':
						// greater than
						$match &= $value > $filter[2];
						break;
				}
			}

			if (count($item->children))
			{
				$this->filterTree($item->children, $filters);

				if (count($item->children) == 0)
				{
					if ($match)
					{
						unset($item->children);
						$item->leaf = TRUE;
					}
					else
					{
						$item = FALSE;
					}
				}
			}

			if ($item->leaf && !$match)
			{
				$item = FALSE;
			}
		}

		$tree = array_values(array_filter($tree));
	}

	public function generateTree($data, $options = array())
	{
		$a = array();

		foreach (array('name', 'parent', 'children', 'leaf') as $key)
		{
			$$key = isset($options[$key]) ? $options[$key] : $key;
		}

		foreach ($data as &$item)
		{
			$a[md5($item->$parent)][] = &$item;
		}

		foreach ($data as &$item)
		{
			// node is not found in parents, so it must be a child
			$item->$leaf = !isset($a[md5($item->$name)]);

			// assign children to parents
			if (!$item->$leaf)
			{
				$item->$children = $a[md5($item->$name)];
				$item->expanded = $options['expanded'];
			}

			unset($item->$parent);
		}

		$keys = array_keys($a);

		return $a[$keys[0]];
	}

	/*
	 * Return the contents of a file
	 */
	public function getFileContents($fileId)
	{
		return file_get_contents($this->getFileName($fileId));
	}

	/*
	 * Return the file id of a referenced file
	 */
	private function getFileId($folderId, $file)
	{
		$sql = sprintf('
			SELECT
				file_id
			FROM
				file
			WHERE
				migration_id = %d
				AND folder_id = %d
				AND file = "%s"
		', $this->migration_id, $folderId, $file);
		$result = mysqli_query($this->db, $sql);

		$file = $result->fetch_object();

		$result->close();

		return (int)$file->file_id;
	}

	public function getFiles($folderId, $filter = '', $start = 0, $limit = 0)
	{
		$data = array();
		$fids = join(',', $this->getFolderIds($folderId));

		$sql = sprintf('
			SELECT
				fi.file_id,
				fo.path,
				fi.file,
				1 AS cardinality
			FROM
				file fi,
				folder fo
			WHERE
				fi.migration_id = %d
				AND fi.folder_id = fo.folder_id
				AND fo.folder_id IN (%s)
				%s
			ORDER BY
				fo.path,
				fi.file
			%s
		', $this->migration_id, $fids, $filter ? sprintf('AND fi.file LIKE "%%%s%%"', $filter) : '', $limit ? sprintf('LIMIT %d, %d', $start, $limit) : '');

		$result = mysqli_query($this->db, $sql);

		while ($row = $result->fetch_object())
		{
			$row->exists = file_exists(sprintf('%s/%s', $row->path, $row->file)) ? 1 : 0;
			$data[] = $row;
		}

		$result->close();

		return $data;
	}

	/*
	 * Return the name of a file
	 */
	public function getFileName($fileId)
	{
		$sql = sprintf('
			SELECT
				CONCAT(fo.path, "/", fi.file) AS file
			FROM
				file fi,
				folder fo
			WHERE
				fi.migration_id = %d
				AND fi.folder_id = fo.folder_id
				AND fi.file_id = %d
		', $this->migration_id, $fileId);
		$result = mysqli_query($this->db, $sql);

		$file = $result->fetch_object();

		$result->close();

		return $file->file;
	}

	/*
	 * Get folders.
	 * $read = 0 -- all folders
	 * $read = 1 -- unread folders
	 * $read = 2 -- read folders
	 */
	public function getFolders($options = array())
	{
		$data = array();
		$parents = array();

		$whereRead = '';
		if (isset($options['read']))
		{
			$whereRead = $options['read'] ? 'AND fo.progress = 100' : 'AND fo.progress < 100';
		}

		$whereName = '';
		if (isset($options['name']))
		{
			$whereName = sprintf('AND SUBSTRING_INDEX(fo.path, "/", -1) LIKE "%%%s%%"', $options['name']);
		}

		$limit = '';
		if (isset($options['limit']))
		{
			$limit = 'LIMIT ' . $options['limit'];
		}

		$sql = '
			SELECT
				fo.folder_id,
				SUBSTRING_INDEX(fo.path, "/", -1) AS name,
				fo.path,
				LEFT(fo.path, LENGTH(fo.path) - LOCATE("/", REVERSE(fo.path))) AS parent,
				(SELECT COUNT(1) FROM file fi WHERE fi.folder_id = fo.folder_id) AS files,
				fo.progress
			FROM
				folder fo
			WHERE
				fo.migration_id = %d
				%s
				%s
			ORDER BY
				parent
			%s
		';

		// find matching folders
		$result = mysqli_query($this->db, sprintf($sql, $this->migration_id, $whereRead, $whereName, $limit));

		while ($row = $result->fetch_object())
		{
			$data[$row->folder_id] = $row;
			$parents[] = $row->parent;
		}
		$result->close();

		// find parents of matching folders
		$parents = array_unique($parents);

		if (isset($options['tree']))
		{
			while (strpos($parents[0], '/') !== false)
			{
				$temp = array();

				foreach ($parents as $id => $parent)
				{
					$result = mysqli_query($this->db, sprintf($sql, $this->migration_id, '', sprintf('AND fo.path = "%s"', $parent), ''));

					while ($row = $result->fetch_object())
					{
						$data[$row->folder_id] = $row;
						$temp[] = $row->parent;
					}
					$result->close();
				}

				$parents = array_unique($temp);
			}
		}

		// sort folders by path
		usort($data, function($a, $b)
		{
			return strcmp($a->path, $b->path);
		});

		return $data;
	}

	/*
	 * Return the id of a referenced folder
	 */
	public function getFolderId($path)
	{
		$sql = sprintf('
			SELECT
				folder_id
			FROM
				folder
			WHERE
				migration_id = %d
				AND path = "%s"
		', $this->migration_id, $path);
		$result = mysqli_query($this->db, $sql);

		$folder = $result->fetch_object();

		$result->close();

		return (int)$folder->folder_id;
	}

	public function getFolderIds($folderId)
	{
		$fids = array();
		$sql = sprintf('
			SELECT
				fo.folder_id
			FROM
				folder fo
			WHERE
				fo.path LIKE CONCAT((SELECT path FROM folder WHERE folder_id = %d), "%%")
		', $folderId);

		$result = mysqli_query($this->db, $sql);

		while ($row = $result->fetch_object())
		{
			$fids[] = $row->folder_id;
		}

		return $fids;
	}

	/*
	 * Return the id of a referenced folder
	 */
	public function getFolderPath($folderId)
	{
		$sql = sprintf('
			SELECT
				path
			FROM
				folder
			WHERE
				migration_id = %d
				AND folder_id = %d
		', $this->migration_id, $folderId);
		$result = mysqli_query($this->db, $sql);

		$folder = $result->fetch_object();

		$result->close();

		return $folder->path;
	}

	public function getPathFiles($folderId, $filter, $pathHash, $conforms = 1, $start = 0, $limit = 0)
	{
		$data = array();
		$fids = join(',', $this->getFolderIds($folderId));
		$sql = array(
			sprintf('
				SELECT
					fi.file_id,
					fo.path,
					fi.file
				FROM
					folder fo,
					file fi
				WHERE
						fi.folder_id IN (%s)
					AND fi.folder_id = fo.folder_id
					AND fi.file_id NOT IN (
						SELECT
							fi.file_id
						FROM
							file fi,
							path p
						WHERE
								fi.folder_id IN (%s)
							AND fi.file_id = p.file_id
							AND MD5(p.path) = "%s"
					)
				ORDER BY
					fo.path,
					fi.file
				%s',
				$fids,
				$fids,
				$pathHash,
				$limit ? sprintf('LIMIT %d, %d', $start, $limit) : ''
			),
			sprintf('
				SELECT
					fi.file_id,
					fo.path,
					fi.file,
					p.cardinality
				FROM
					file fi,
					folder fo,
					path p
				WHERE
						fi.folder_id IN (%s)
					AND fi.file_id = p.file_id
					AND fi.folder_id = fo.folder_id
					AND MD5(p.path) = "%s"
					%s
				ORDER BY
					fo.path,
					fi.file
				%s',
				$fids,
				$pathHash,
				$filter ? sprintf('AND fi.file LIKE "%%%s%%"', $filter) : '',
				$limit ? sprintf('LIMIT %d, %d', $start, $limit) : ''
			)
		);

		$result = mysqli_query($this->db, $sql[$conforms]);

		while ($row = $result->fetch_object())
		{
			$row->exists = file_exists(sprintf('%s/%s', $row->path, $row->file)) ? 1 : 0;
			$data[] = $row;
		}

		$result->close();

		return $data;
	}

	private function parseFiles()
	{
		// get totals of files processed
		foreach (array('pending' => 'IS NULL', 'started' => '=' . PERCENT_STARTED, 'complete' => '=' . PERCENT_COMPLETE) as $state => $value)
		{
			$sql[] = sprintf('(SELECT COUNT(1) FROM file WHERE migration_id = %d AND progress %s) AS %s', $this->migration_id, $value, $state);
		}
		$sql = 'SELECT ' . join(',', $sql);

		$result = mysqli_query($this->db, $sql);

		$totals = $result->fetch_object();

		$inc = PERCENT_COMPLETE / ($totals->pending + $totals->started + $totals->complete);
		$num = $totals->complete;

		$result->close();

		// parse files
		$sql = sprintf('
			SELECT
				fi.file_id,
				fo.folder_id,
				fo.path,
				fi.file
			FROM
				folder fo,
				file fi
			WHERE
				fi.migration_id = %d
				AND fo.folder_id = fi.folder_id
				AND fi.progress IS NULL
		', $this->migration_id);

		$result = mysqli_query($this->db, $sql);

		while ($file = $result->fetch_object())
		{
			$this->updateFileProgress($file->file_id, PERCENT_STARTED);

			$path = $this->fileToPaths(sprintf('%s/%s', $file->path, $file->file));

			if ($this->pathsToDb($path, $file->file_id))
			{
				$this->updateFileProgress($file->file_id, PERCENT_COMPLETE);
				$this->updateProgress(STAGE_PARSE_FILES, $inc * ++$num);
			}
			else
			{
				$this->updateFileProgress($file->file_id, NULL);
			}
		}

		$result->close();

		return true;
	}

	private function pathsToDb($paths, $fileId)
	{
		$batch = array();
		$sql = 'INSERT INTO path (migration_id, file_id, path, cardinality) VALUES %s';
		$fieldMask = '(%d, %d, "%s", %d)';

		foreach ($paths as $hash => $data)
		{
			$batch[] = array($this->migration_id, $fileId, mysqli_real_escape_string($this->db, $data['_path']), $data['_cardinality']);

			if (count($batch) == BATCH_INSERT_SIZE)
			{
				$values = array();
				for ($i = 0; $i < count($batch); $i++)
				{
					$values[] = sprintf($fieldMask, $batch[$i][0], $batch[$i][1], $batch[$i][2], $batch[$i][3]);
				}

				mysqli_query($this->db, sprintf($sql, join(',', $values)));

				$batch = array();
			}
		}

		if (count($batch))
		{
			$values = array();
			for ($i = 0; $i < count($batch); $i++)
			{
				$values[] = sprintf($fieldMask, $batch[$i][0], $batch[$i][1], $batch[$i][2], $batch[$i][3]);
			}

			mysqli_query($this->db, sprintf($sql, join(',', $values)));
		}

		return true;
	}

	private function registerFiles($folder)
	{
		$path = $folder->path;
		$list = scandir($path);
		$inc = PERCENT_COMPLETE / count($list);
		$batch = array();
		$fieldMask = '(%d, %d, "%s")';
		$num = 0;
		$sql = 'INSERT INTO file (migration_id, folder_id, file) VALUES %s';

		foreach ($list as $name)
		{
			if (!is_dir("$path/$name") && preg_match($this->file_type_regex, $name))
			{
				if (!$this->getFileId($folder->folder_id, $name))
				{
					$batch[] = array($this->migration_id, $folder->folder_id, $this->tools->encode($name));

					// create batch of file references
					if (count($batch) == BATCH_INSERT_SIZE)
					{
						$values = array();
						for ($i = 0; $i < count($batch); $i++)
						{
							$values[] = sprintf($fieldMask, $batch[$i][0], $batch[$i][1], $batch[$i][2]);
						}

						mysqli_query($this->db, sprintf($sql, join(',', $values)));

						// reset batch
						$batch = array();
					}
				}
			}

			++$num;
		}

		// create batch of file references
		if (count($batch))
		{
			$values = array();
			for ($i = 0; $i < count($batch); $i++)
			{
				$values[] = sprintf($fieldMask, $batch[$i][0], $batch[$i][1], $batch[$i][2]);
			}

			mysqli_query($this->db, sprintf($sql, join(',', $values)));
		}

		$this->updateFolderProgress($folder->folder_id, $inc * $num);

		return true;
	}

	private function registerFolder($path)
	{
		$list = array_diff(scandir($path), array('.', '..'));
		$count = 0;

		foreach ($list as $name)
		{
			$fileSpec = "$path/$name";

			if (is_dir($fileSpec))
			{
				// recursive folder scan
				$count += $this->registerFolder($fileSpec);
			}
			else
			{
				// the folder has files we're interested in
				$count += preg_match($this->file_type_regex, $name) ? 1 : 0;
			}
		}

		if ($count)
		{
			if (!$this->getFolderId($path))
			{
				// create the file reference
				mysqli_query($this->db, sprintf('
					INSERT
					INTO folder
						(migration_id, path)
					VALUES
						(%d, "%s")
				', $this->migration_id, $this->tools->encode($path)));
			}
		}

		return $count;
	}

	public function run()
	{
		while ($this->stage < STAGE_COMPLETE)
		{
			switch (true)
			{
				case $this->stage == STAGE_STARTED:
					$this->updateProgress(STAGE_REGISTER_FOLDERS);
					break;

				case $this->stage == STAGE_REGISTER_FOLDERS && $this->progress < PERCENT_COMPLETE:
					if ($this->registerFolder($this->data_path))
					{
						$this->updateProgress(STAGE_REGISTER_FILES);
					}
					break;

				case $this->stage == STAGE_REGISTER_FOLDERS && $this->progress == PERCENT_COMPLETE:
					$this->updateProgress(STAGE_REGISTER_FILES);
					break;

				case $this->stage == STAGE_REGISTER_FILES && $this->progress < PERCENT_COMPLETE:
					$unreadFolders = $this->getFolders(array('read' => false));
					$readFolders = $this->getFolders(array('read' => true));
					$read = count($readFolders);
					$inc = PERCENT_COMPLETE / ($read + count($unreadFolders));

					foreach ($unreadFolders as $folder)
					{
						if ($this->registerFiles($folder))
						{
							$this->updateProgress(STAGE_REGISTER_FILES, $inc * ++$read);
						}
					}
					$this->updateProgress(STAGE_PARSE_FILES);
					break;

				case $this->stage == STAGE_REGISTER_FILES && $this->progress == PERCENT_COMPLETE:
					$this->updateProgress(STAGE_PARSE_FILES);
					break;

				case $this->stage == STAGE_PARSE_FILES && $this->progress < PERCENT_COMPLETE:
					if ($this->parseFiles())
					{
						$this->updateProgress(STAGE_COMPLETE);
					}
					break;

				default:
					break;
			}
		}
	}

	private function updateFileProgress($fileId, $progress)
	{
		mysqli_query($this->db, sprintf('
			UPDATE
				file fi
			SET
				fi.progress = %f
			WHERE
				fi.migration_id = %d
				AND fi.file_id = %d
		', $progress, $this->migration_id, $fileId));
	}

	private function updateFolderProgress($folderId, $progress)
	{
		$sql = sprintf('
			UPDATE
				folder fo
			SET
				fo.progress = %f
			WHERE
				fo.migration_id = %d
				AND fo.folder_id = %d
		', $progress, $this->migration_id, $folderId);

		mysqli_query($this->db, $sql);
	}

	private function updateProgress($stage, $progress = 0)
	{
		if ($stage > $this->stage)
		{
			$progress = max(0, $progress);
		}

		$sql = sprintf('
			UPDATE
				migration m
			SET
				m.stage = %d,
				m.progress = %f
			WHERE
				m.migration_id = %d
		', $stage, $progress, $this->migration_id);

		mysqli_query($this->db, $sql);

		$this->stage = $stage;
		$this->progress = $progress;
	}

	public function viewFile($fileId)
	{
		$file = $this->getFileName($fileId);
		$contents = file_get_contents($file);

		return $contents;
	}

}
?>