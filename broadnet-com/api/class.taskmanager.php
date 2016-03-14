<?php

/*
 * TaskManager class.
 * 
 * Defines a task manager.
 */
class TaskManager
{
	/*
	 * Database connection resource
	 */
	private $db;

	/*
	 * Class constructor
	 */
	public function __construct()
	{
		$this->db = mysqli_connect('localhost', 'broadnet', 'pa55word!', 'broadnet')
			or die('Error ' . mysqli_error());
	}

	/*
	 * Add a task to the database
	 * 
	 * @param Task $task the task to add
	 */
	public function addTask(&$task)
	{
		$sql = sprintf('
			INSERT
			INTO
				task (title)
			VALUES
				("%s")
		', mysql_escape_string($task->title));

		mysqli_query($this->db, $sql);
		
		$task->id = mysqli_insert_id($this->db);
	}

	/*
	 * Delete a task from the database
	 * 
	 * @param int $id the task id
	 */
	public function deleteTask($id)
	{
		$sql = sprintf('
			DELETE
			FROM
				task
			WHERE
				id = %d
		', $id);

		mysqli_query($this->db, $sql);
	}

	/*
	 * Get a task from the database
	 * 
	 * @param int $id the task id
	 * @return Task
	 */
	public function getTask($id)
	{
		$sql = sprintf('
			SELECT 
				id, title
			FROM
				task
			WHERE
				id = %d
		', $id);

		$result = mysqli_query($this->db, $sql);
		
		$row = $result->fetch_object();

		$result->close();

		$task = new Task();
		$task->id = $row->id;
		$task->title = $row->title;

		return $task;
	}	

	/*
	 * Get a JSON encoded list of tasks
	 * 
	 * @return string
	 */
	public function getTasks()
	{
		$tasks = array();
		$sql = sprintf('
			SELECT
				id, title
			FROM
				task
		');		

		$result = mysqli_query($this->db, $sql);

		while ($row = $result->fetch_object())
		{
			$task = new Task();
			$task->id = $row->id;
			$task->title = $row->title;

			$tasks[] = json_decode((string)$task);
		}

		$result->close();

		return json_encode($tasks);
	}

	/*
	 * Update a task
	 * 
	 * @param Task $task the task to update
	 */
	public function updateTask(&$task)
	{
		$sql = sprintf('
			UPDATE
				task
			SET
				title = "%s"
			WHERE
				id = %d
		', mysql_escape_string($task->title), $task->id);		

		mysqli_query($this->db, $sql);
	}
}
