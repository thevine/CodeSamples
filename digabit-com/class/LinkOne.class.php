<?php

define('FILETYPE_LINKONE_INF', 'book.inf');
define('FILETYPE_LINKONE_LDF', '.ldf');
define('LINKONE_INCLUDE', 'INCLUDE');
define('LOBA_SCRIPT', 'loba.sh');
define('LOBA_CMD', 'java -jar {jarfile} -B"{bomfilesdir}" -C"{contentfilesdir}" -Etrue -Ffalse -H{hkey} -K{supplierkey} -LDEBUG -MEA -NPNNA -O"{outputdir}" -Pdefault -Q"{logdir}" -Rtrue -S"{toplevelldf}" -T{tenantkey} -Ytrue >> {logdir}/logfile 2>&1');

class LinkOneFileTools extends FileTools
{
	public function __construct()
	{
		parent::__construct();
		$this->fileObject = 'LinkOneFile';
	}

	public function copyReferencedFiles($files, $target_folder, $path_map, $ref_file = false)
	{
		foreach ($files as $file)
		{
			$path = str_replace('\\', '/', $file);
			$info = (object)pathinfo($path);
			$extn = '';
			
			if ($path == $info->filename)
			{
				$this->logging->printLine(sprintf('ignoring file path: "%s"', $path));
				continue;
			}
			
			try {
				$extn = strlen($info->extension) ? '.' . $info->extension : '';
			} catch (ErrorException $e) {
				echo sprintf("path '%s' exception: %s\n", $path, $e->getMessage());
				var_dump($path);
				var_dump($info);
			}

			if (array_key_exists(strtolower($info->dirname), $path_map))
			{
				$mapd = $path_map[strtolower($info->dirname)];
				$source_file = sprintf('%s/%s%s', $mapd, $info->filename, strtolower($extn));

				if ($info->basename && array_key_exists(strtolower($info->dirname), $path_map))
				{
					$this->addAction(ACTION_COPY_VERSION, array(
						// source file name
						$source_file,
						
						// target folder
						$target_folder,
	
						// reference file
						$ref_file,
						
						// make a backup of the reference file
						FALSE
					));
				}
			}
			else
			{
				if ($this->logging)
				{
					$this->logging->printLine(sprintf('unable to copy file: "%s/%s%s" -> "%s"',
						$info->dirname, $info->filename, $extn, $target_folder
					));
				}
			}
		}
	}

	public function getFileReplacements($filename = 'file-replacements.txt')
	{
		$pairs = array();
		$filepath = DATA_PATH . "/$filename";

		if (file_exists($filepath))
		{
			$lines = $this->getFile($filepath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	
			foreach ($lines as $line)
			{
				list($path, $str) = explode(':', $line, 2);
				$path = strtolower(trim($path));
				$str = trim($str);

				// inserts
				$search = preg_replace('/<<.*>>/', '', $str);
				$replace = preg_replace('/<<(.*)>>/', '$1', $str);
				if ($search !== $replace)
				{
					$pairs[$path][] = array('/' . preg_quote($search, '/') . '/', $replace);
				}

				// deletes
				$search = preg_replace('/>>(.*)<</', '$1', $str);
				$replace = preg_replace('/>>.*<</', '', $str);
				if ($search !== $replace)
				{
					$pairs[$path][] = array('/' . preg_quote($search, '/') . '/', $replace);
				}
			}
		}

		return $pairs;
	}

	public function getFileTypeRegex()
	{
		return array(
			'/^(EMBEDDED,)([^,]*)(,.*)$/m',
			'/^(GRAPHIC[,]+)([^,]*)$/m',
			'/^(PICTURE,\d+,[^,]*,[^,]*,\d+,\d+,\d+,)([^,]*)(.*)?$/m'
		);
	}

	// find the referenced INCLUDEed file...
	public function getIncludeFiles($source_root, $includes = array())
	{
		$files = array();

		foreach ($includes as $include)
		{
			$name = trim($include[0]);
			$path = sprintf('%s/%s', $source_root, $name);

			// if the exact path name doesn't exist
			// attempt to find the closest "exact" match
			if (!file_exists($path))
			{
				$path = $this->findClosest($source_root, $name, GLOB_ONLYDIR);
			}
			
			if ($path)
			{
				$file = $this->getLDFFileByListId($path, trim($include[1]));

				if ($file && $file->location())
				{
					$files[] = $file;
				}
			}
		}

		return $files;
	}

	public function getINFFiles($folders)
	{
		return $this->getFiles($folders, array(FILETYPE_LINKONE_INF));
	}

	public function getLDFFileByListId($folder, $list_id)
	{
		$info = (object)pathinfo($folder);
		$list = array();
		$dbname = sprintf('%s/%s.json', JSON_PATH, $info->basename);

		if (file_exists($dbname))
		{
			$list = json_decode(file_get_contents($dbname), true);
		}
		else
		{
			$ldf_files = $this->getLDFFiles(array($folder));

			foreach ($ldf_files as $file)
			{
				$list[$file->realName(FILETYPE_LINKONE_LDF)] = $file->location();
			}

			file_put_contents($dbname, json_encode($list));
		}

		if (array_key_exists($list_id, $list))
		{
			return new LinkOneFile($list[$list_id]);
		}

		return FALSE;
	}

	public function getLDFFiles($folders)
	{
		return $this->getFiles($folders, array(FILETYPE_LINKONE_LDF));
	}

	public function getLDFOrigins($files = array())
	{
		$names = array();
		$links = array();
		$hash = array();

		foreach ($files as $file)
		{
			$realname = $file->realName(FILETYPE_LINKONE_LDF);
			$hash[$realname] = $file;
			$names[] = $realname;
			$links = array_merge($links, $file->links());
		}

		foreach (array_values(array_diff($names, $links)) as $key)
		{
			$origins[$key] = $hash[$key];
		}

		usort($origins, array($this, 'mostLikely'));

		return $origins;
	}

	public function getReferencedFiles($contents)
	{
		$refs = array();

		foreach ($this->getFileTypeRegex() as $regex)
		{
			// process image files
			$matched = array();

			if (preg_match_all($regex, $contents, $matched, PREG_PATTERN_ORDER))
			{
				// found them...
				for ($i = 0; $i < count($matched[0]); $i++)
				{
					$ref = trim($matched[2][$i], " \n\r\"");
					
					if (strlen($ref))
					{
						$refs[] = $ref;
					}
				}
			}
		}

		return $refs;
	}

	public function isTopLevelLDF($name)
	{
		static $top_level = NULL;

		if ($top_level == NULL)
		{
			$top_level = array();
			$file = $this->getFile(DATA_PATH . '/top-level.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

			foreach ($file as $item)
			{
				$a = explode(',', strtoupper($item)); // book/machine #, ldf name
				$top_level[] = $a[count($a)-1];
			}
		}

		return in_array($name, $top_level);
	}

	private static function mostLikely($_1, $_2)
	{
		return count($_1->links()) > count($_2->links()) ? -1 : 1;
	}
}

class LinkOneFile extends File
{
	private $entry;
	private $include;
	private $realname;
	private $links;
	private $lines;

	public function __construct($fileSpec)
	{
		parent::__construct($fileSpec);
		$this->entry = NULL;
		$this->links = NULL;
		$this->lines = array('LIST' => array());
		$this->realname = NULL;

	}

	public function entries()
	{
		if ($this->entry == NULL)
		{
			$this->include = $this->readLines('ENTRY');
		}

		return $this->entry;
	}

	public function includes()
	{
		if ($this->include == NULL)
		{
			$this->include = $this->readLines('INCLUDE');
		}

		return $this->include;
	}

	public function lines($type)
	{
		return $this->lines[$type];
	}

	public function links()
	{
		if ($this->links == NULL)
		{
			$this->links = array();

			foreach ($this->entries() as $entry)
			{
				$this->links[] = $entry[10];
			}

			$this->links = array_values(array_filter(array_unique($this->links)));
		}

		return $this->links;
	}

	public function realName($type)
	{
		switch ($type)
		{
			case FILETYPE_LINKONE_INF:
				$pattern = '/BOOK,([^,]*)/';
				break;

			case FILETYPE_LINKONE_LDF:
				$pattern = '/LIST,([^,]*)/';
				break;

			default:
				return false;
		}

		if ($this->realname == NULL)
		{
			foreach ($this->read() as $line)
			{
				$match = array();
				if (preg_match($pattern, $line, $match))
				{
					$this->realname = $match[1];
					break;
				}
			}
		}

		return $this->realname;
	}
}

class LOBACommand
{
	private $jarFile;
	private $properties = array();

	public function __construct($jarFile, $properties = array())
	{
		$this->properties['jarfile'] = $jarFile;

		foreach ($properties as $name => $value)
		{
			$this->setProperty($name, $value);
		}
	}

	public function getCommandString()
	{
		$cmd = LOBA_CMD;
		$matched = array();

		while (preg_match('/\{(\w+)\}/', $cmd, $matched))
		{
			$cmd = str_replace('{' . $matched[1] . '}', $this->properties[$matched[1]], $cmd);
		}

		return $cmd;
	}

	public function getProperty($name)
	{
		return $this->properties[$name];
	}

	public function setProperty($name, $value)
	{
		$this->properties[$name] = $value;
	}
}
