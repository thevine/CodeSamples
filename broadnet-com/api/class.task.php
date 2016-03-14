<?php

/*
 * Task class.
 * 
 * Defines a single task.
 */
class Task
{
	/*
	 * The task id
	 */
	private $id;

	/*
	 * The task title
	 */
	private $title = '';

	/*
	 * The class constructor
	 * 
	 * @return object
	 */
	public function __construct()
	{
		return $this;
	}

	/*
	 * The task property getter
	 * 
	 * @return mixed
	 */
	public function __get($name)
	{
		return $this->$name;
	}

	/*
	 * The task property setter
	 */
	public function __set($name, $value)
	{
		$this->$name = $value;
	}

	/*
	 * The task stringifier
	 * 
	 * @return string
	 */
	public function __toString()
	{
		return json_encode(array(
			'id' => $this->id,
			'title' => $this->title
		));
	}
}
