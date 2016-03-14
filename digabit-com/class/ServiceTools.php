<?php
/*
 * ServiceTools
 *
 * (c) Digabit, 2015
 *
 * Author: steve.neill@digabit.com
 *
 */

class ServiceTools
{
	private $charset;
	private $db;

	public function __construct()
	{
		$this->charset = 'Windows-1252';
		$this->db = mysqli_connect('localhost', 'root', 'ihop123!', 'servicetools') or die('Error ' . mysqli_error());
	}

	private function createDatabase()
	{
		$fileSql = "
			CREATE TABLE file (
				file_id INT(11) NOT NULL AUTO_INCREMENT,
				folder_id INT(11) NOT NULL,
				migration_id INT(11) NOT NULL,
				file VARCHAR(200) NOT NULL,
				progress TINYINT(3) UNSIGNED NULL DEFAULT NULL,
				INDEX file_id (file_id),
				INDEX file_spec (folder_id, file)
			)
		";
		
		$folderSql = "
			CREATE TABLE folder (
				folder_id INT(11) NOT NULL AUTO_INCREMENT,
				migration_id INT(11) NOT NULL DEFAULT '0',
				path VARCHAR(400) NULL DEFAULT '0',
				progress FLOAT NOT NULL DEFAULT '0',
				INDEX id (folder_id)
			)
		";
			
		$pathSql = "
			CREATE TABLE path (
				migration_id INT(11) UNSIGNED NOT NULL,
				file_id INT(11) UNSIGNED NOT NULL,
				path VARCHAR(500) NOT NULL,
				cardinality INT(10) UNSIGNED NOT NULL DEFAULT '0',
				unique_values INT(10) UNSIGNED NOT NULL DEFAULT '0'
			)
		";
		
		$migrationSql = "
			CREATE TABLE migration (
				migration_id INT(11) NOT NULL AUTO_INCREMENT,
				name VARCHAR(30) NOT NULL,
				type VARCHAR(3) NULL DEFAULT NULL,
				data_path VARCHAR(200) NOT NULL,
				file_type VARCHAR(10) NOT NULL,
				stage TINYINT(3) UNSIGNED NULL DEFAULT '0',
				progress FLOAT UNSIGNED NULL DEFAULT NULL,
				INDEX id (migration_id),
				INDEX migration (data_path)
			)
		";
	}

	private function createMigration($type, $name, $dataPath, $fileType)
	{
		$migration_id = $this->getMigrationId($name);
		$dataPath = str_replace('\\', '/', $dataPath);

		if (!$migration_id)
		{
			// create the migration
			$sql = sprintf('
				INSERT INTO
					migration (name, type, data_path, file_type) 
				VALUES
					("%s", "%s", "%s", "%s")
			', $this->encode($name), $type, $this->encode($dataPath), $fileType);

			mysqli_query($this->db, $sql);

			// get the migration id
			$migration_id = mysqli_insert_id($this->db);
		}

		$migration = $this->getMigrationById($migration_id);

		return $migration;
	}

	public function createLDFMigration($name, $dataPath, $fileType = 'ldf')
	{
		return $this->createMigration('LDF', $name, $dataPath, $fileType);
	}

	public function createXMLMigration($name, $dataPath, $fileType = 'xml')
	{
		return $this->createMigration('XML', $name, $dataPath, $fileType);
	}

	public function encode($str)
	{
		return mb_convert_encoding($str, 'UTF-8', $this->charset);
	}

	public function getDb()
	{
		return $this->db;
	}

	public function getMigrationById($migrationId)
	{
		$sql = sprintf('
			SELECT
				m.migration_id,
				m.name,
				m.type,
				m.data_path,
				m.file_type,
				(SELECT COUNT(1)
					FROM folder fo
					WHERE fo.migration_id = m.migration_id) AS folders,
				(SELECT COUNT(1)
					FROM file fi
					WHERE fi.migration_id = m.migration_id) AS files,
				m.stage,
				m.progress
			FROM
				migration m
			WHERE
				m.migration_id = %d
		', $migrationId);

		$result = mysqli_query($this->db, $sql);

		$row = $result->fetch_object();

		$class = strtoupper($row->type) . 'ServiceMigration';
		$migration = new $class();

		$migration->migration_id = $row->migration_id;
		$migration->name = $row->name;
		$migration->data_path = $row->data_path;
		$migration->file_type = $row->file_type;
		$migration->file_type_regex = sprintf('/\.%s$/', $row->file_type);
		$migration->stage = $row->stage;
		$migration->progress = $row->progress;

		return $migration;
	}

	/*
	 * Return the migration id
	 */
	public function getMigrationId($name)
	{
		// get the migration id
		$sql = sprintf('
			SELECT
				m.migration_id
			FROM
				migration m
			WHERE
				m.name = "%s"
			LIMIT
				1
		', $this->encode($name));

		$result = mysqli_query($this->db, $sql);
		$migration = $result->fetch_object();
		$result->close();

		return $migration->migration_id;
	}

	public function getMigrations()
	{
		$migrations = array();
		$sql = '
			SELECT
				m.migration_id,
				m.name,
				UPPER(m.type) AS type,
				m.data_path,
				m.file_type,
				(SELECT COUNT(1)
					FROM folder fo
					WHERE fo.migration_id = m.migration_id) AS folders,
				(SELECT COUNT(1)
					FROM file fi
					WHERE fi.migration_id = m.migration_id) AS files,
				m.stage,
				((m.stage * 100) + m.progress) / 4 AS progress
			FROM
				migration m
			ORDER BY
				m.name
		';

		$result = mysqli_query($this->db, $sql);

		while ($migration = $result->fetch_object())
		{
			$migrations[] = $migration;
		}

		$result->close();

		return $migrations;
	}
}
?>
