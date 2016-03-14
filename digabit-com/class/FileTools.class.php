<?php

include_once (LIB_PATH . '/Strings.class.php');

if (!defined('DATA_PATH')) define('DATA_PATH', '../__data__');
if (!defined('JSON_PATH')) define('JSON_PATH', '../__json__');

define('ACTION_MAKE_FOLDER', 'makefolder');
define('ACTION_COPY_FILE', 'copyfile');
define('ACTION_COPY_VERSION', 'copyversion');
define('ACTION_CONTENT_REPLACE', 'contentreplace');
define('ACTION_BATCH_FILE', 'batchfile');
define('RESULT_UNKNOWN', 0);
define('RESULT_DRYRUN', 1);
define('RESULT_FAILED', 2);
define('RESULT_SUCCESS', 3);
define('RESULT_FILE_NOT_EXIST', 4);
define('RESULT_FOLDER_NOT_EXIST', 5);
define('RESULT_FILE_EXISTS', 6);
define('RESULT_FOLDER_EXISTS', 7);
define('RESULT_UNABLE_TO_CREATE', 8);
define('RESULT_UNABLE_TO_DELETE', 9);
define('RESULT_UNABLE_TO_COPY', 10);
define('RESULT_NOT_WRITABLE', 11);
define('RESULT_NOT_READABLE', 12);

class FileTools
{
	private $actions = array();
	private $dryrun = FALSE;
	protected $logging;
	protected $strings;
	protected $fileObject;
	protected $fileType;

	public function __construct()
	{
		$this->strings = new Strings();
		$this->fileObject = 'File';
		$this->logging = NULL;
		$this->createFolder(JSON_PATH);
	}

	public function addAction($type, $action)
	{
		$this->actions[$type][] = $action;
	}

	public function backupFile($filename)
	{
		$info = (object)pathinfo($filename);
		
		return $this->copyFile($filename, sprintf('%s/%s.~%s', $info->dirname, $info->filename, $info->extension));
	}

	public function codeString($code = RESULT_UNKNOWN)
	{
		$str = array(
			RESULT_UNKNOWN => 'Unknown',
			RESULT_DRYRUN => 'ry run',
			RESULT_SUCCESS => 'OK',
			RESULT_FAILED => 'The operation failed',
			RESULT_FILE_NOT_EXIST => 'The file does not exist',
			RESULT_FOLDER_NOT_EXIST => 'The folder does not exist',
			RESULT_FILE_EXISTS => 'The file already exists',
			RESULT_FOLDER_EXISTS => 'The folder already exists',
			RESULT_UNABLE_TO_CREATE => 'Unable to create',
			RESULT_UNABLE_TO_DELETE => 'Unable to delete',
			RESULT_UNABLE_TO_COPY => 'Unable to copy',
			RESULT_NOT_WRITABLE => 'File not writable',
			RESULT_NOT_READABLE => 'File not readable'
		);

		return $str[$code];
	}

	public function copyFile($source_file, $target_file, $alt_source_file = FALSE)
	{
		if ($this->dryrun)
		{
			return FALSE;
		}

		if (is_readable($source_file)) {
			if (is_writable(dirname($target_file))) {
				if (copy($source_file, $target_file))
				{
					return RESULT_SUCCESS;
				}
				else {
					return $alt_source_file ? $this->copyFile($alt_source_file, $target_file) : RESULT_UNABLE_TO_COPY;
				}
			}
			else
			{
				return RESULT_NOT_WRITABLE;
			}
		}
		else
		{
			return $alt_source_file ? $this->copyFile($alt_source_file, $target_file) : RESULT_NOT_READABLE;
		}
	}

	public function copyFileVersion($source_file, $target_folder, &$target_file)
	{
		if (!is_file($source_file))
		{
			return RESULT_FILE_NOT_EXIST;
		}

		if (!is_dir($target_folder))
		{
			return RESULT_FOLDER_NOT_EXIST;
		}

		$source_info = (object)pathinfo($source_file);
		$source_md5 = md5_file($source_file);
		$target_info = (object)pathinfo($target_folder);
		$target_file = sprintf('%s/%s', $target_folder, $source_info->basename);

		// does the file exist in the target?
		$suffix = 0;
		while (file_exists($target_file) && $source_md5 !== md5_file($target_file))
		{
			// same name but different contents! -- add a suffix
			$target_file = sprintf('%s/%s_[%05d].%s', $target_folder, $source_info->filename, ++$suffix, $source_info->extension);
		}

		if (file_exists($target_file))
		{
			return RESULT_FILE_EXISTS;
		}
		else if (!$this->dryrun)
		{
			return $this->copyFile($source_file, $target_file);
		}

		return FALSE;
	}

	public function createFolder($path, $perms = 0777)
	{
		if ($this->dryrun)
		{
			return FALSE;
		}

		if (file_exists($path))
		{
			return RESULT_SUCCESS;
		}
		else
		{
			if (@mkdir($path, $perms, true))
			{
				return RESULT_SUCCESS;
			}
		}

		return RESULT_UNABLE_TO_CREATE;
	}

	public function deleteFile($filename)
	{
		if ($this->dryrun)
		{
			return FALSE;
		}

		if (file_exists($filename))
		{
			return unlink($filename);
		}

		return FALSE;
	}

	public function executeActions()
	{
		static $batch_count = 0;

		// execute actions
		foreach ($this->getActionTypes() as $type)
		{
			if (!count($this->actions[$type]))
			{
				continue;
			}

			foreach ($this->actions[$type] as $a)
			{
				$log = array();

				switch ($type)
				{
					case ACTION_BATCH_FILE:
						$this->writeFile($a[0], $a[1] . "\n");

						$log[] = sprintf('%s: "%s" <- "%s"', $type, $a[0], $a[1]);
						break;

					case ACTION_COPY_FILE:
						$result = $this->copyFile($a[0], $a[1]);
						$log[] = sprintf('%s (%s): "%s" -> "%s"', $type, $this->codeString($result), $a[0], $a[1]);
						break;

					case ACTION_COPY_VERSION:
						$target_file = '';
						$result = $this->copyFileVersion($a[0], $a[1], $target_file);
						$source = (object)pathinfo($a[0]);

						$log[] = sprintf('%s (%s): "%s" -> "%s"', $type, $this->codeString($result), $a[0], $target_file);

						// try an alternate version of the source file
						if (!in_array($result, array(RESULT_SUCCESS, RESULT_FILE_EXISTS)))
						{
							if ($alternate = $this->findClosest($source->dirname, $source->basename))
							{
								$log[] = sprintf('found alternate: "%s"', $alternate);
								$target_file = '';
								$result = $this->copyFileVersion($alternate, $a[1], $target_file);
								$log[] = sprintf('%s (%s): "%s" -> "%s"', $type, $this->codeString($result), $alternate, $target_file);
							}
						}

						// has the target filename changed? if so, we need to update the file contents itself
						$target = (object)pathinfo($target_file);

						if ($a[2] && $source->basename !== $target->basename && file_exists($target_file))
						{
							$this->actions[ACTION_CONTENT_REPLACE][] = array(
								'/' . preg_quote($source->basename, '/') . '/',
								$target->basename,
								$a[2],
								@$a[3]/*make a backup*/
							);
						}
						break;

					case ACTION_CONTENT_REPLACE:
						if ($count = $this->replaceFileContent($a[0], $a[1], $a[2], $a[3]))
						{
							$log[] = sprintf('%s: "%s" -> "%s" in "%s" (%d replacements)', $type, $a[0], $a[1], $a[2], $count);
						}
						break;

					case ACTION_MAKE_FOLDER:
						$result = $this->createFolder($a[0]);
						$log[] = sprintf('%s (%s): "%s"', $type, $this->codeString($result), $a[0]);
						break;

					default:
						$log[] = $this->codeString(RESULT_FAILED);
						break;
				}

				if ($this->logging && count($log))
				{
					foreach ($log as $line)
					{
						$this->logging->printLine($line, 1);
					}
				}
			}
		}
	}

	public function findClosest($path, $name, $flags = NULL)
	{
		static $cache = array();
		$key = "$path/$name";

		if (!array_key_exists($key, $cache))
		{
			$matched = preg_grep("/($name)/i", glob("$path/*", $flags));
			$cache[$key] = count($matched) ? array_pop($matched) : FALSE;
		}

		return $cache[$key];
	}

	public function getActions()
	{
		return $this->actions;
	}

	public function getActionTypes()
	{
		return array(ACTION_MAKE_FOLDER, ACTION_COPY_FILE, ACTION_COPY_VERSION, ACTION_CONTENT_REPLACE, ACTION_BATCH_FILE);
	}

	public function getFile($filename, $flags = 0)
	{
		return file($filename, $flags);
	}

	public function getFiles($paths = array(), $types = array(), $deep = false)
	{
		$list = array();
		$files = array();
		$pattern = '/(' . str_replace('.', '\.', join('|', $types)) . ')$/i';

		foreach ($paths as $path)
		{
			$list = array_merge($list, array_diff(scandir(trim($path)), array('.', '..')));
		}

		foreach ($list as $name)
		{
			$filename = "$path/$name";

			if ($deep && is_dir($filename))
			{
				$files = array_merge($files, $this->getFiles(array($filename), $types, $deep));
			}
			else
			{
				// the folder contains files we're interested in
				if (preg_match($pattern, $name))
				{
					if (is_file($filename))
					{
						$files[] = new $this->fileObject($filename);
					}
				}
			}
		}

		// sort files
		usort($files, array($this, 'natural_file_sort'));
		
		return $files;
	}

	public function getFolders($path, $reset = true)
	{
		static $folders = array();

		// static array better than merging returned array
		$list = array_diff(scandir(trim($path)), array('.', '..'));

		if ($reset)
		{
			$folders = array();
		}

		$folders[] = $path;

		foreach ($list as $name)
		{
			$folder = "$path/$name";

			if (is_dir($folder))
			{
				if ($this->logging)
				{
					$this->logging->printLine("found folder: $folder");
				}

				$this->getFolders($folder, false);
			}
		}

		return $folders;
	}

	function getFoldersList($paths = array(), $saveFile = false)
	{
		$dbname = sprintf('%s/%s.json', JSON_PATH, $saveFile);

		// load list of folders to scan
		if ($saveFile && file_exists($dbname))
		{
			// load folders list
			$folders = json_decode(file_get_contents($dbname), true);
		}
		else
		{
			$folders = array();

			foreach ($paths as $path)
			{
				$folders = array_merge(
					$folders,
					$this->getFolders($path)
				);
			}

			// save folders list
			if ($saveFile)
			{
				file_put_contents($dbname, json_encode($folders));
			}
		}

		return $folders;
	}

	public function getLogging()
	{
		return $this->logging;
	}

	private function natural_file_sort($a, $b)
	{
		return strnatcmp($a->filename(), $b->filename());
	}

	public function replaceFileContent($search, $replace, $filename, $backup = false)
	{
		if ($this->dryrun)
		{
			return FALSE;
		}

		$count = 0;
		$old = file_get_contents($filename);
//print "[ $search | $replace ]\n";
		$new = preg_replace($search, $replace, $old, -1, $count);

		if ($count)
		{
			if ($backup)
			{
				$this->backupFile($filename);
			}

			file_put_contents($filename, $new);
		}

		return $count;
	}

	public function resetActions()
	{
		$this->actions = array();

		foreach ($this->getActionTypes() as $type)
		{
			$this->actions[$type] = array();
		}
	}

	public function setLogging($logging)
	{
		$this->logging = $logging;
	}

	public function setDryRun($dryrun = true)
	{
		$this->dryrun = $dryrun;
	}

	public function writeFile($filename, $data, $append = true)
	{
		if ($this->dryrun)
		{
			return FALSE;
		}

		if ($fh = fopen($filename, $append ? 'a' : 'w'))
		{
			fwrite($fh, $data);
			fclose($fh);
		}
	}
}

class File
{
	private $altname;
	private $contents;
	private $lines;
	private $location;
	private $info;
	private $strings;

	public function __construct($filename)
	{
		$this->altname = NULL;
		$this->location = $filename;
		$this->info = (object)pathinfo($filename);
		$this->strings = new Strings;
	}

	public function altname($altname = NULL)
	{
		if ($altname !== NULL)
		{
			$this->altname = $altname;
		}
		
		return $this->altname == NULL ? $this->basename() : $this->altname;
	}

	public function basename()
	{
		return $this->info->basename;
	}

	public function contents()
	{
		if ($this->contents == NULL)
		{
			$this->contents = file_get_contents($this->location);
		}

		return $this->contents;
	}

	public function dirname()
	{
		return $this->info->dirname;
	}

	public function extension()
	{
		return $this->info->extension;
	}

	public function filename()
	{
		return $this->info->filename;
	}

	public function location()
	{
		return $this->location;
	}

	public function parseCSV($str)
	{
		return $this->strings->parseCSV($str);
	}

	public function read()
	{
		if ($this->lines == NULL)
		{
			$this->lines = file($this->location);
		}

		return $this->lines;
	}

	public function readLines($type = '')
	{
		$lines = array();

		foreach ($this->read() as $line)
		{
			$match = array();
			if (preg_match('/^' . $type . ',(.*)/', $line, $match))
			{
				$lines[] = $this->parseCSV($match[1]);
			}
		}

		return $lines;
	}

	public function size()
	{
		return filesize($this->location);
	}
}

