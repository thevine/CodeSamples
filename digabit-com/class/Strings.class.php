<?php

class Strings
{
	private $stringTypes;

	public function __construct()
	{
		$this->stringTypes = array(
			array('empty', '-', '^$'),
			array('integer', 'n', '^(?:0|[1-9][0-9]*)$'),
			array('decimal', 'n', '^[0-9]+\.[0-9]{1,}$'),
			array('date', 'd', '^\d{4}-\d{2}-\d{2}$'),
			array('file:doc', 'F', '^[^\s]+(\.(?i)(doc|pdf|txt))$'),
			array('file:img', 'F', '^[^\s]+(\.(?i)(bmp|gif|jpg|png|tif))$'),
			array('name', 'N', '^[_a-z]\w+$'),
			array('token', 'T', '^[\w-_.:]+$'),
			array('string', 's', '\s')
		);
	}

	/*
	 * This function converts the non-standard Windows 'smart quotes'
	 * into standard html entities. For example, a left quote in
	 * a MS Word application will render as a curved quote (top left to bottom right),
	 * but when writing to XML or buffered as output to browser, shows up
	 * as junk characters. These windows based characters, generally
	 * coming from Excel or other MS applications, need to be converted
	 * into standard quotes or html entities for writing to XML, or other
	 * useful output.
	 */
	function convertWindowsSmartQuotes($string)
	{
		$search = array(chr(0xe2) . chr(0x80) . chr(0x98), chr(0xe2) . chr(0x80) . chr(0x99), chr(0xe2) . chr(0x80) . chr(0x9c), chr(0xe2) . chr(0x80) . chr(0x9d), chr(0xe2) . chr(0x80) . chr(0x93), chr(0xe2) . chr(0x80) . chr(0x94));

		//$replace = array('&#8216;', '&#8217;', '&#8220;', '&#8221;', '&#150;', '&#x2014;');
		$replace = array("'", "'", '"', '"', '-', '-');

		return str_replace($search, $replace, $string);
	}

	public function endsWith($haystack, $needle)
	{
		return substr($haystack, -strlen($needle)) === $needle;
	}

	public function getFormat($value)
	{
		foreach ($this->stringTypes as $type => $item)
		{
			if (preg_match('/' . $item[2] . '/', $value))
			{
				$len = strlen($value);
				return $item[1] . ($len ? $len : '');
			}
		}

		return 's:' . strlen($value);
	}

	public function getType($value)
	{
		foreach ($this->stringTypes as $type => $item)
		{
			if (preg_match('/' . $item[2] . '/', $value))
			{
				return $item[0];
			}
		}

		return '?';
	}

	public function parseCSV($str, $commaDelim = ',', $quoteDelim = '"')
	{
		$chars = str_split($str);
		$quoted = FALSE;
		$values = array();
		$value = '';

		foreach ($chars as $char)
		{
			switch ($char)
			{
				case $quoteDelim:
					$quoted = !$quoted;
					break;

				case $commaDelim:
					if (!$quoted)
					{
						$values[] = $value;
						$value = '';
						break;
					}

				default:
					$value .= $char;
					break;
			}
		}

		if (strlen($value))
		{
			$values[] = $value;
		}

		return $values;
	}

	public function startsWith($haystack, $needle)
	{
		return strpos($haystack, $needle) === 0;
	}
}
?>