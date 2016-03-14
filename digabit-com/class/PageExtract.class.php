<?php

include_once (LIB_PATH . '/Strings.class.php');

define('CANVAS_MARGIN', 2);
define('PHANTOM_PATH', '/home/ubuntu/ServiceTools/3rdparty/phantomjs/bin/phantomjs');

class PageExtract {
	protected $strings;

	public function __construct() {
		$this->strings = new Strings();
	}

	public function nodesToImageCanvas($nodes) {
		if (!count($nodes)) {
			return false;
		}

		$canvas = (object) array('top' => PHP_INT_MAX, 'left' => PHP_INT_MAX, 'height' => 0, 'width' => 0);

		foreach ($nodes as $node) {
			$metric = $node->metrics;

			$canvas->top = min($canvas->top, $metric->top - CANVAS_MARGIN);
			$canvas->left = min($canvas->left, $metric->left - CANVAS_MARGIN);
			$canvas->height = max($canvas->height, $metric->top + $metric->height + CANVAS_MARGIN);
			$canvas->width = max($canvas->width, $metric->left + $metric->width + CANVAS_MARGIN);
			$canvas->area = $canvas->height * $canvas->width;
			$canvas->orientation = $canvas->height > $canvas->width ? 'portrait' : 'landscape';
		}

		$canvas->height -= $canvas->top;
		$canvas->width -= $canvas->left;

		return $canvas;
	}

	public function dataPointsToTextGroups($data) {
		// sort data points on x-y-axis
		usort($data, array($this, 'sortByPosition'));

		// identify potential rows (items that share the same y-axis)
		$groups = array();
		foreach ($data as $item) {
			$groups[md5($item->top)][] = $item;
		}

		$continued = 0;
		$bom = array();
		$hotpoint = array();
		$page = array();
		$misc = array();
		$count = $this->getLikelyColumnCount($groups);

		foreach ($groups as $group) {
			// separate out items according to their most likely types
			switch (true) {
				case count($group) == 1 && $this->groupContainsMatch($group, '/^\d+$/') :
					$hotpoint[] = $group;
					break;

				case $this->groupContainsMatch($group, '/^\(continued\)$/'):
					$continued = 1;
					break;

				case $this->groupContainsMatch($group, '/^Page \d+$/') :
					foreach ($group as $item)
					{
						$page[] = $group;					
					}
					break;

				case count($group) == 4 :
					$bom[] = $group;
					break;

				default:
					//foreach ($group as $item)
					//{
					//	$misc[] = $item;					
					//}
					$misc[] = $group;
					break;
			}
		}

		$title = array(array_shift($misc));

		// probably not a BOM -- file it under miscellaneous
		if (count($bom) == 1) {
			foreach ($bom as $item) {
				$misc[] = $item;
			}

			$bom = array();
		}

		// finally, see if there are any "orphan" hotpoints in the "misc" data
		foreach ($misc as $group) {
			$temp = array();

			foreach ($group as $item) {
				if (preg_match('/^\d+$/', $item->value)) {
					$temp[] = array($item);
				}
			}

			if (count($temp) == count($group)) {
				$hotpoint = array_merge($hotpoint, $temp);
			}
		}

		return array('continued' => $continued, 'title' => $title, 'bom' => $bom, 'hotpoint' => $hotpoint, 'page' => $page, 'misc' => $misc);
	}

	private function findContainer($nodes, $num)
	{
		while (!isset($nodes[$num]->metrics))
		{
			--$num;
		}

		return $num;
	}

	function generateImage($canvas)
	{
		$cmd = sprintf('%s %s/js/image.js "%s" "%s" %d %d %d %d', PHANTOM_PATH, LIB_PATH, $canvas->source, $canvas->target, $canvas->top, $canvas->left, $canvas->height, $canvas->width);
		$output = array();
		$return = NULL;

		exec($cmd, $output, $return);
		
		return $return;
	}

	private function getAttributes(&$dom)
	{
		$attrs = (object)array('id' => NULL);

		// attributes
		if ($dom->hasAttributes) {
			while ($dom->moveToNextAttribute()) {
				$attrs->{$dom->name} = $dom->value;
			}
		}
		
		// extract css style attributes
		if (property_exists($attrs, 'style')) {
			$styles = split(';', trim($attrs->style));
			foreach ($styles as $pairs) {
				$pair = split(':', trim($pairs));

				if ($pair[0]) {
					$attrs->{$pair[0]} = trim($pair[1]);
				}
			}
		}

		return $attrs;
	}

	public function getPageNodes($file) {
		$json = exec(sprintf('%s %s/js/metrics.js "%s"', PHANTOM_PATH, LIB_PATH, $file));
		$nodes = json_decode($json);

		return $nodes;
	}

	public function getDataPoints($page) {
		$file = $page->location();
		$dom = new XMLReader();
		$level = array();
		$parts = array('text' => array(), 'image' => array());
		$inItem = false;
		$itemId = 0;
		$json = exec(sprintf('%s %s/js/metrics.js "%s"', PHANTOM_PATH, LIB_PATH, $file));
		$nodes = json_decode($json);
		$num = 0;
		$dom->open($file);

		$attrs = NULL;
		$prev_attrs = NULL;

		while ($dom->read()) {
			// check for empty element early
			$isEmptyElement = $dom->isEmptyElement;

			switch (true) {
				case $dom->nodeType == XMLReader::ELEMENT :
					// path
					$localName = $dom->localName;
					$level[] = ($dom->prefix ? "$dom->prefix:" : '') . $localName;
					$path = join('/', $level);

					$prev_attrs = $attrs;
					$attrs = $this->getAttributes($dom);

					$nodes[$num]->id = $attrs->id;
					$nodes[$num]->path = $path;

					switch (true) {
						// identify images
						case $localName == 'img' && !preg_match('/^data:/', $attrs->src):
							$image_file = sprintf("%s/%s", $page->dirname(), $attrs->src);
							
							if (!is_dir($image_file) && file_exists($image_file))
							{
/*
								$image_extn = substr($image_file, -4);
								$image = new imagick($image_file);
								$color_count = $image->getImageColors();
	
								switch (true)
								{
									case $image_extn == '.jpg':
									case $color_count <= 15:
									case $color_count >= 75:
*/
										$parts['image'][++$itemId] = $nodes[$num]->metrics;
/*
										break;
										
									default:
										print "$image_file ... $color_count\n";
										copy($image_file, '/tmp/rejected/' . $attrs->src);
										break;
								}
	
								$image->destroy();
*/
							}
							break;

						// Special case: Group all items within <p> tags as one item...
						case $localName == 'p' :
							$inItem = true;
							++$itemId;
							break;

						default :
							break;
					}
					++$num;
					break;

				case $dom->nodeType == XMLReader::TEXT:
					if ($inItem) {						
						if (!isset($parts['text'][$itemId])) {
							$parts['text'][$itemId] = $nodes[$this->findContainer($nodes, $num)]->metrics;
						}
$metrics = $parts['text'][$itemId];
$prev_metrics = $parts['text'][$itemId-1];

						$parts['text'][$itemId]->value .= $this->strings->convertWindowsSmartQuotes(trim($dom->value, "\t"));
print_r($prev_metrics);
print_r($metrics);
						// special cases
						if (
								// tab delimiter
								preg_match('/\t$/', $dom->value)
								
								// next item is to the left of the last item
						   ||	($prev_attrs->left && ((int)$attrs->left < (int)$prev_attrs->left))
						   
						   		// next item is sufficiently separated from the last item
						   ||	((int)$attrs->left > (int)$prev_attrs->left + (int)$prev_attrs->width + 20)
						   )
						{
							++$itemId;
						}
					}

					++$num;
					break;

				default :
					break;
			}

			if ($isEmptyElement || $dom->nodeType == XMLReader::END_ELEMENT) {
				if ($dom->localName == 'p') {
					$inItem = false;
				}

				array_pop($level);
			}
		}

		$dom->close();

		return $parts;
	}

	private function getLikelyColumnCount($groups) {
		$counts = array();

		// count occurences of group items
		foreach ($groups as $group) {
			if (!array_key_exists(count($group), $counts))
			{
				$counts[count($group)] = 0;
			}
			++$counts[count($group)];
		}

		// sort, least to most common
		asort($counts);

		// discount groups with only 1 item
		unset($counts[1]);

		$keys = array_keys($counts);

		return array_pop($keys);
	}

	private function groupContainsMatch($group, $pattern) {
		foreach ($group as $item) {
			if (preg_match($pattern, $item->value)) {
				return true;
			}
		}

		return false;
	}

	public function outputCanvas($canvas, $hotpoints) {
		$html = array();

		if ($canvas) {
			$html[] = '<h3>image canvas</h3>';
			$html[] = sprintf('<div class="canvas" style="background-image:url(%s); height:%dpx; width:%dpx;">', $canvas->url, $canvas->height, $canvas->width);

			foreach ($hotpoints as $hotpoint) {
				$hotpoint = $hotpoint[0];
				$hotpoint->top -= @$canvas->top;
				$hotpoint->left -= @$canvas->left;

				$html[] = sprintf('<span class="hotpoint" style="top:%dpx; left:%dpx;">%s</span>', $hotpoint->top, $hotpoint->left, $hotpoint->value);
			}

			$html[] = '</div>';
		}

		return join("\n", $html);
	}

	public function outputTable($rows, $type, $detailed = false) {
		$html = array();
		$html[] = sprintf('<h3>%s</h3>', $type);

		if (count($rows[$type]) == 0) {
			$html[] = '(none)';
		} else {
			$html[] = '<table border="1">';

			foreach ($rows[$type] as $row) {
				$class = 'ok';
				if ($type == 'misc' && count($row) > 1) {
					$class = 'fault';
				}

				$html[] = sprintf('<tr class="%s">', $class);

				if (is_array($row)) {
					foreach ($row as $cell) {
						$html[] = sprintf('<td>%s</td>', $cell->value);

						if ($detailed) {
							$html[] = '<td class="detail">';
							foreach ($cell as $name => $value) {
								$html[] = sprintf('%s = %s<br/>', $name, $value);
							}
							$html[] = '</td>';
						}
					}
				}

				$html[] = '</tr>';
			}

			$html[] = '</table>';
		}

		return join("\n", $html);
	}


	// sort Top-Left to Bottom-Right
	public function sortLR($a, $b)
	{
		if ($a->left == $b->left)
		{
			return 0;
		}

		return ($a->left < $b->left) ? -1 : 1;
	}

	// sort Top-Left to Bottom-Right
	public function sortTLBR($a, $b)
	{
		if ($a->top == $b->top) {
			return $this->sortLR($a, $b);
		}
		else
		{
			return ($a->top < $b->top) ? -1 : 1;
		}
	}
}
