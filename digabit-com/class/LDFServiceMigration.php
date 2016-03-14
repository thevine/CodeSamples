<?php
/*
 * LDFServiceMigration
 *
 * (c) Digabit, 2015
 *
 * Author: steve.neill@digabit.com
 *
 */

class LDFServiceMigration extends ServiceMigration
{
	private $strings;

	public function __construct()
	{
		parent::__construct();

		$this->strings = new Strings();
	}

	public function executePath($fileId, $pathHash)
	{
		return "fileId=$fileId; pathHash=$pathHash";
	}

	public function fileToPaths($file)
	{
		$paths = array();

		// open the file
		if (($fh = fopen($file, 'r')) !== false)
		{
			// read lines
			while (($line = fgets($fh)) !== false)
			{
				if (!trim($line)) {
					continue;
				}

				// get fields
				$fields = $this->strings->parseCSV($line);

				// construct path
				$types = array();
				$types[] = array_shift($fields);

				foreach ($fields as $value)
				{
					//$types[] = $this->strings->getType($value);
					$types[] = $this->strings->getFormat($value);
				}

				$path = join('|', $types);
				$hash = md5($path);

				$paths[$hash]['_path'] = $path;
				$paths[$hash]['_cardinality']++;
			}

			fclose($fh);
		}

		return $paths;
	}

	public function getPaths($folderId)
	{
		$data = array();
		$fids = join(',', $this->getFolderIds($folderId));

		$sql = sprintf('
			SELECT
				p.path AS path,
				SUBSTRING_INDEX(p.path, "|", 1) AS name,
				MD5(p.path) AS hash,
				"" AS parent,
				t.total,
				COUNT(p.file_id) AS found,
				(COUNT(1) / t.total) * 100 AS conforms
			FROM
				path AS p,
				file AS fi,
				(SELECT COUNT(*) AS total
					FROM file ft
					WHERE ft.migration_id = %d
					%s
				) AS t
			WHERE
				fi.file_id = p.file_id
				AND fi.migration_id = %d
				%s
			GROUP BY
				p.path
			', $this->migration_id, "AND ft.folder_id IN ($fids)", $this->migration_id, "AND fi.folder_id IN ($fids)");

		$result = mysqli_query($this->db, $sql);

		$max = NULL;
		while ($row = $result->fetch_object())
		{
			// determine max number of files that path is found
			if ($max === NULL)
			{
				$max = $row->found;
			}
			$row->missing = $max - $row->found;
			$data[] = $row;
		}

		$result->close();

		return $data;
	}
}
?>