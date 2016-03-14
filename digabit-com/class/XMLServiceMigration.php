<?php
/*
 * XMLServiceMigration
 *
 * (c) Digabit, 2015
 *
 * Author: steve.neill@digabit.com
 *
 */

class XMLServiceMigration extends ServiceMigration
{
	public function __construct()
	{
		parent::__construct();
	}

	public function executePath($fileId, $pathHash)
	{
		$sql = sprintf('
			SELECT
				p.path
			FROM
				path p
			WHERE
				p.migration_id = %d
				AND p.file_id = %d
				AND MD5(p.path) = "%s"
		', $this->migration_id, $fileId, $pathHash);
		//print $sql;
		$result = mysqli_query($this->db, $sql);
		$query = $result->fetch_object();
		$result->close();

		$markup = array();

		if ($query)
		{
			$doc = new DOMDocument();
			$file = $this->getFileName($fileId);

			// disable errors caused by HTML5 syntax
			libxml_use_internal_errors(true);

			switch ($this->file_type)
			{
				case 'html':
				case 'xhtml':
					$doc->loadHTMLFile($file);
					break;

				default:
					$doc->load($file);
					break;
			}

			// resume error handling
			libxml_clear_errors();

			$xpath = new DOMXPath($doc);
			/**
			 // extract and register namespaces
			 $context = $doc->documentElement;
			 $ns = $xpath->query("/", $context);
			 foreach ($ns as $node)
			 {
			 var_dump($node);
			 $xpath->registerNamespace($node->localName, $node->nodeValue);
			 }
			 **/
			// run the xpath query
			$nodes = $xpath->query($query->path);

			for ($i = 0; $i < $nodes->length; $i++)
			{
				$markup[] = $doc->saveHTML($nodes->item($i));
			}
		}

		return $markup;
	}

	public function fileToPaths($file)
	{
		$paths = array();

		if (is_string($file) && preg_match($this->file_type_regex, $file))
		{
			$tree = new XMLReader();
			$tree->open(func_get_arg(0));
			$level = array('');

			while ($tree->read())
			{
				// check for empty element early because reading attributes will mess it up
				$isEmptyElement = $tree->isEmptyElement;

				switch (true)
				{
					case ($tree->nodeType == XMLReader::ELEMENT):
						// node name
						$name = ($tree->prefix ? "$tree->prefix:" : '') . $tree->localName;
						$level[] = $name;

						$path = join('/', $level);
						$hash = md5($path);
						$paths[$hash]['_path'] = $path;
						$paths[$hash]['_cardinality']++;

						// attributes
						if ($tree->hasAttributes)
						{
							while ($tree->moveToNextAttribute())
							{
								$attrPath = sprintf('%s[@%s]', $path, $tree->name);
								$hash = md5($attrPath);
								$paths[$hash]['_path'] = $attrPath;
								$paths[$hash]['_cardinality']++;

								// special case attributes with values
								if (in_array($tree->name, array('class', 'lang', 'type')))
								{
									$attrPath = sprintf('%s[@%s="%s"]', $path, $tree->name, $tree->value);
									$hash = md5($attrPath);
									$paths[$hash]['_path'] = $attrPath;
									$paths[$hash]['_cardinality']++;
								}
							}
						}
						break;
				}

				if ($isEmptyElement || $tree->nodeType == XMLReader::END_ELEMENT)
				{
					array_pop($level);
				}
			}

			$tree->close();
		}

		return $paths;
	}

	public function getPaths($folderId)
	{
		$data = array();
		$fids = join(',', $this->getFolderIds($folderId));

		$sql = sprintf('
			SELECT
				p.path,
				##REPLACE(SUBSTRING_INDEX(p.path, IF(RIGHT(p.path, 1) = "]", "@", "/"), -1), "]", "") AS name,
				IF(RIGHT(p.path, 1) = "]", REPLACE(SUBSTRING_INDEX(p.path, "[", -1), "]", ""), p.path) AS display,
				MD5(p.path) AS hash,
				LEFT(p.path, LENGTH(p.path) - IF(RIGHT(p.path, 1) <> "]", LOCATE("/", REVERSE(p.path)), LOCATE("[", REVERSE(p.path)))) AS parent,
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
			ORDER BY
				p.path,
				parent
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

	function spatialGrid($fileId)
	{
		return array();
		
		$regex = '/top\:(\-?[0-9]*\.?[0-9]+)px;\s*left\:(\-?[0-9]*\.?[0-9]+)px;(?:[^>]*)>([^<]*)/';
		$filename = $this->getFileName($fileId);
		$contents = file_get_contents($filename);
		$matches = array();
		$rows = array();
		$cols = array();

		preg_match_all($regex, $contents, $matches);

		// gather rows
		for ($i = 0; $i < count($matches[1]); $i++) {
			$rows[$matches[1][$i]][] = array($matches[1][$i], $matches[2][$i], $matches[3][$i]);
		}

		// count unique column positions
		for ($i = 0; $i < count($matches[2]); $i++) {
			++$cols[$matches[2][$i]];
		}

		ksort($rows);
		ksort($cols);

		return $rows;
	}
}
?>