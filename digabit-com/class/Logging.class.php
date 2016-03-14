<?php

if (!defined('LOG_PATH')) define('LOG_PATH', '../__logs__');
/*
set_error_handler(function ($errno, $errstr, $errfile, $errline, array $context)
{
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
*/
class Logging
{
	private $filename;
	private $filehandle;
	private $percent = 0;
	private $count = 0;

	public function __construct($file)
	{		
		if (file_exists($file))
		{
			unlink($file);
		}

		$this->filename = $file;
		$this->filehandle = fopen($file, 'a');
	}

	public function end()
	{
		if ($this->filehandle)
		{
			fclose($this->filehandle);
		}
	}
	
	public function printLine($line, $indent = 0, $percent = 0)
	{
		static $_indent = 0;

		if ($indent == 0)
		{
			$indent = $_indent;
		}

		$_indent = $indent;

		$t = microtime(true);
		$micro = sprintf("%06d", ($t - floor($t)) * 1000000);
		$d = new DateTime(date('Y-m-d H:i:s.' . $micro, $t));

		$out = sprintf("[%s  %08d  %6.2f%%] %s%s\n",
			$d->format("Y-m-d H:i:s.u"),
			++$this->count,
			$percent == 0 ? $this->percent : $percent,
			str_repeat(' ', $_indent * 3),
			$line
		);

		if ($this->filehandle)
		{
			fwrite($this->filehandle, $out);
		}
	}
	
	public function printLines($lines, $indent = 0, $percent = 0)
	{
		foreach ($lines as $line)
		{
			$this->printLine($line, $indent, $percent);
		}
	}

	public function setCount($count)
	{
		$this->count = $count;
	}

	public function setPercent($percent)
	{
		$this->percent = $percent;
	}
}

?>