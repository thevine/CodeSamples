<?php

class DocuModel {
	private $id;
	private $name;
	private $db;

	public function __construct($name) {
		$this->db = mysqli_connect('localhost', 'root', 'ihop123!', 'documodel') or die('Error ' . mysqli_error());

		if ($model = $this->getModelByName($name)) {
			$this->id = $model->id;
			$this->name = $model->name;			
		}
		else {
			// create the model
			$sql = sprintf('
				INSERT INTO
					dataset (`name`) 
				VALUES
					("%s")
			', $name);

			mysqli_query($this->db, $sql);

			// get the model id
			$this->id = mysqli_insert_id($this->db);
			$this->name = $name;
		}
	}

	public function addBOM($page_id, $hotpoint_id, $part_num, $description = '', $quantity) {
		// create a BOM entry
		$sql = sprintf('
			INSERT INTO
				bom (`page_id`, `hotpoint_id`, `part_num`, `description`, `quantity`) 
			VALUES
				(%d, "%s", "%s", "%s", "%s")',
			$page_id,
			$hotpoint_id,
			$part_num,
			$description,
			$quantity
		);

		mysqli_query($this->db, $sql);		

		return mysqli_insert_id($this->db);
	}

	public function addDatapoint($page_id, $top, $left, $center, $text) {
		// create a datapoint entry
		$sql = sprintf('
			INSERT INTO
				datapoint (`page_id`, `top`, `left`, `center`, `text`) 
			VALUES
				(%d, %d, %d, %d, "%s")',
			$page_id,
			$top,
			$left,
			$center,
			$text
		);

		mysqli_query($this->db, $sql);		

		return mysqli_insert_id($this->db);
	}

	public function addHotpoint($page_id, $top, $left, $bottom, $right, $height, $width, $value) {
		// create a hotpoint entry
		$sql = sprintf('
			INSERT INTO
				hotpoint (`page_id`, `top`, `left`, `bottom`, `right`, `height`, `width`, `value`) 
			VALUES
				(%d, %d, %d, %d, %d, %d, %d, %d)',
			$page_id,
			$top,
			$left,
			$bottom,
			$right,
			$height,
			$width,
			$value
		);

		mysqli_query($this->db, $sql);		

		return mysqli_insert_id($this->db);
	}
	
	public function addNodes($page_id, $nodes)
	{
		$values = array();

		foreach ($nodes as $node)
		{
			$text = mysqli_real_escape_string($this->db, $node->value);
			$values[] = sprintf('(%d,%d,%d,%d,%d,%d,"%s")', $page_id, $node->top, $node->left, $node->center, $node->bottom, $node->right, $text);
		}

		if (count($values))
		{
			$sql = '
				INSERT INTO
					node (`page_id`, `top`, `left`, `center`, `bottom`, `right`, `text`)
				VALUES ' . join(',', $values);

			mysqli_query($this->db, $sql);
		}
	}

	public function addMisc($page_id, $group_id, $value) {
		// create a misc. entry
		$sql = sprintf('
			INSERT INTO
				misc (`page_id`, `group_id`, `value`) 
			VALUES
				(%d, %d, "%s")',
			$page_id,
			$group_id,
			$value
		);

		mysqli_query($this->db, $sql);		

		return mysqli_insert_id($this->db);
	}

	public function addPage($source_url, $image_file) {
		// create a page
		$sql = sprintf('
			INSERT INTO
				page (`dataset_id`, `source_url`, `image_file`) 
			VALUES
				(%d, "%s", "%s")',
			$this->id,
			$source_url,
			$image_file
		);

		mysqli_query($this->db, $sql);

		return mysqli_insert_id($this->db);
	}

	public function call($spName)
	{
		$conn = mysqli_connect('localhost', 'root', 'ihop123!', 'documodel') or die('Error ' . mysqli_error());
		$result = mysqli_query($conn, "CALL $spName()", MYSQLI_STORE_RESULT) or die(mysqli_error($conn));
		$rows = array();
		
		if ($result)
		{
			while ($row = mysqli_fetch_object($result))
			{
	        	$rows[] = $row;
	    	}

			$result->close();
		}

		$conn->close();

		return $rows;
	}

	private function getModelByName($name)
	{
		$sql = sprintf('
			SELECT
				d.*
			FROM
				dataset d
			WHERE
				d.name = "%s"
		', $name);

		$result = mysqli_query($this->db, $sql);
		$row = $result->fetch_object();
		mysqli_free_result($result);
		
		return $row;
	}

	public function getPageRangeImages($pageIds)
	{
		$rows = array();
		$sql = sprintf('
			SELECT
				p.image_file
			FROM
				page p
			WHERE
				p.id BETWEEN %d AND %d
				AND p.image_file > ""
		', min($pageIds) - 1, max($pageIds));
//print $sql;
		if ($result = mysqli_query($this->db, $sql))
		{
			while ($row = $result->fetch_object())
			{
				$file = $row->image_file;
				$path = '/tmp/images/' . $file;
				$info = pathinfo($file);
				$size = getimagesize($path);
				$hash = md5_file($path);

	        	$rows[$hash] = (object)array(
	        		'filepath' => $path,
					'basename' => $info['basename'],
					'filename' => $info['filename'],
					'height' => $size[1],
					'width' => $size[0],
					'hash' => $hash
	        	);
			}

			mysqli_free_result($result);
		}

		return $rows;
	}
}

