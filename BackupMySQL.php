<?php
/*
	BackupMySQL.php — 2017-V-7 — Francisco Cascales
 	— Backup a MySQL database only with PHP (without mysqldump)
	— https://github.com/fcocascales/phpbackupmysql
	— Version 1.13b

	Example 1:
			// Download a SQL backup file
			require_once "BackupMySQL.php";
			$backup = new BackupMySQL([
				'host'=> "localhost",
				'database'=> "acme",
				'user'=> "root",
				'password'=> "",
			]);
			$backup->download();

	Example 2:
			// Download a ZIP backup file
			require_once "BackupMySQL.php";
			$connection = [
				'host'=> "localhost",
				'database'=> "acme",
				'user'=> "root",
				'password'=> "",
			];
			$tables = [
				"wp_*",
				"mytable1",
			];
			$show = [
				'TABLE',
				'DATA'
			];
			$backup = new BackupMySQL($connection, $tables, $show);
			$backup->zip();
			$backup->download();

	Example 3:
			// Stores a SQL backup file to a writable folder
			require_once "BackupMySQL.php";
			$setup = [
				'connection'=> [
					'host'=> "localhost",
					'database'=> "acme",
					'user'=> "root",
					'password'=> "",
				],
				'tables'=> "wp_*,mytable1",
				'show'=> "TABLE,DATA",
				'folder'=> "../backups",
			];
			$backup = new BackupMySQL();
			$backup->setConnection ($setup['connection']);
			$backup->setTables ($setup['tables']);
			$backup->setShow ($setup['show'])
			$backup->setFolder ($setup['folder']);
			$backup->run();

	TODO:
		- Avoid timeout with database too large

	DONE:
		- Extract the FOREIGN KEY of CREATE TABLE sentence.
		- Compress SQL file to ZIP file (without shell)
		- Method to download the SQL or ZIP file (using header)
		- Detect a temporary writable folder to store SQL & ZIP files
		- Delete file after download
		- Publish in GitHub
		- Can be set the name of the backup
		- setTables(["*", "table1",...]) means all tables except table1, etc
		- Use LIMIT with big tables (avoid out of memory)
		- Code of triggers
*/

class BackupMySQL {

	//——————————————————————————————————————————————
	// CONSTANTS

	const ROWS_PER_LIMIT = 10000; // 10000
	const ROWS_PER_INSERT = 1000; // 1000

	//——————————————————————————————————————————————
	// ATTRIBUTES

	private $connection = array( // Database parameters connection
		'host'=> "localhost",
		'database'=> "acme",
		'user'=> "root",
		'password'=> ""
	);

	private $tables = array( // Backup selection tables (by default all)
		'wp_*',
		'table1',
		'table2',
	);

	private $show = array( // (By default all)
		'DATABASE', // Generate SQL to create and use DB
		'TABLES', // Generate SQL to drop and create TABLEs
		'VIEWS', // Generate SQL to create or replace VIEWs
		'PROGRAMS', // Generate SQL to drop and create PROCEDUREs and FUNCTIONs
		'TRIGGERS', // Generate SQL to drop and create TRIGGERs
		'DATA', // Generate SQL to truncate tables and dump data
	);

	private $name = ""; // Backup file name (by default database name)
	private $folder = ""; // Backup target folder (by default temporary folder)

	//——————————————————————————————————————————————
	// CONSTRUCTOR

	public function __construct($connection=array(), $tables=array(), $show=array()) {
		$this->setConnection($connection);
		$this->setTables($tables);
		$this->setShow($show);
		$this->setName("");
		$this->setFolder(self::getTempFolder());
	}

	private static function getTempFolder() {
		return ini_get('upload_tmp_dir')? ini_get('upload_tmp_dir') : sys_get_temp_dir();
	}

	//——————————————————————————————————————————————
	// SETTERS

	public function setConnection($assoc) {
		$this->connection = $assoc;
	}
	public function setTables($array) {
		if (empty($array)) $this->tables = array();
		elseif (!is_array($array)) $this->tables = explode(',', $array);
		else $this->tables = $array;
	}
	public function setShow($array) {
		if (empty($array)) $this->show = array();
		elseif (!is_array($array)) $this->show = explode(',', $array);
		else $this->show = $array;
	}
	public function setName($string) {
		$this->name = $string;
	}
	public function setFolder($string) {
		$this->folder = $string;
	}

	//——————————————————————————————————————————————
	// PUBLIC METHODS

	/*
		Creates a database backup in a SQL file

		Example:
			$backup->run();
			echo $backup->getPath();
	*/
	public function run() {
		try {
			$this->initDatabase();
			$this->initFile();
			$this->initTables();
			$this->initViews();
			$this->openFile();
				$this->backupDB();
			$this->closeFile();
		}
		catch (Exception $ex) {
			die($ex->getMessage());
		}
	}

	/*
		Creates a database backup and show it in the web browser
	*/
	public function test() {
		try {
			header("Content-Type: text/plain");
			$this->initDatabase();
			$this->initTables();
			$this->initViews();
			$this->backupDB();
		}
		catch (Exception $ex) {
			die($ex->getMessage());
		}
	}

	/*
		Creates a database backup in a ZIP file

		Example:
			$backup->zip();
			echo $backup->getPath();
	*/
	public function zip() {
		if (empty($this->path)) $this->run();
		$path = $this->getPath();
		$zip = rtrim($path, '.sql').'.zip';
		$za = new ZipArchive();
		if ($za->open($zip, ZipArchive::CREATE) !== true)
			throw new Exception ("Cannot open $zip");
		$za->addFile($path, basename($path));
		$za->close(); // Fatal error: Maximum execution time of 30 seconds exceeded (87 MB)
		unlink($path);
		$this->path = $zip;
	}

	/*
		Creates a database backup in a SQL or ZIP file
		and starts the download

		Ejemplo 1:
			$backup->download();

		Ejemplo 2:
			$backup->run();
			$backup->zip();
			$backup->download();
	*/
	public function download($purge=true) {
		if (empty($this->path)) $this->run();
		$path = $this->getPath();
		$quoted = sprintf('"%s"', addcslashes(basename($path), '"\\'));
		$ext = pathinfo($path, PATHINFO_EXTENSION);
		header('Content-Description: File Transfer');
		//header('Content-Type: application/octet-stream');
		header("Content-Type: application/$ext");
		header('Content-Disposition: attachment; filename='.$quoted);
		header('Content-Transfer-Encoding: binary');
		header('Connection: Keep-Alive');
		header('Expires: 0');
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Content-Length: '.filesize($path));
		echo file_get_contents($path);
		if ($purge) unlink($path);
	}

	//——————————————————————————————————————————————
	// GETTERS

	/*
		Get the path of the SQL or ZIP backup file
		or empty text
	*/
	public function getPath() {
		return $this->path;
	}

	//——————————————————————————————————————————————
	// FILE

	private $file = null;
	private $path = "";
	private $buffer = "";

	private function initFile() {
		$name = empty($this->name)? $this->connection['database'] : $this->name;
		$time = date('Y-m-d_H-i-s'); //time();
		$folder = empty($this->folder)? "" : rtrim($this->folder, '/').'/';
		$this->path = "{$folder}{$name}_{$time}.sql";
	}

	private function openFile() {
		$this->file = fopen($this->path,'w+');
		if ($this->file === false) throw new Exception("Failed to open $this->path");
	}

	private function append($text) {
		if ($this->file == null) echo $text;
		else $this->buffer .= $text;
	}

	private function writeFile() {
		if ($this->file == null) return;
		fwrite($this->file, $this->buffer);
		$this->buffer = "";
	}

	private function closeFile() {
		if ($this->file == null) return;
		$this->writeFile();
		fclose($this->file);
	}

	//——————————————————————————————————————————————
	// INIT DATABASE

	private $pdo = null;
	private $dbtables = array();
	private $dbviews = array();

	private function initDatabase() {
		extract($this->connection); // $host, $database, $user, $password
		$charset = "utf8";
		$string = "mysql:host=$host;dbname=$database;charset=$charset";
		$pdo = new PDO($string, $user, $password);
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec("SET NAMES $charset");
		$pdo->exec("SET CHARACTER SET $charset");
		$this->pdo = $pdo;
	}

	private function initTables() {
		$this->dbtables = $this->findTables('TABLE', $this->tables);
	}
	private function initViews() {
		$this->dbviews = $this->findTables('VIEW', $this->tables);
	}
	private function findTables($like, $tables) {
		$database = $this->connection['database'];
		$sql = "SHOW FULL TABLES IN $database WHERE TABLE_TYPE LIKE '%$like%'";
		$result = $this->pdo->query($sql);
		$all = $result->fetchAll(PDO::FETCH_COLUMN);
		if (empty($tables)) return $all;
		else if ($tables[0] == '*') return $this->findTablesToSubstract($all, $tables);
		else return $this->findTablesToAdd($all, $tables);
	}
	public function findTablesToAdd($all, $tables) {
		$result = array();
		foreach ($all as $table) {
			if ($this->matchTable($table, $tables)) $result[]= $table;
		}
		return $result;
	}
	public function findTablesToSubstract($all, $tables) {
		$result = array();
		array_shift($tables); // Removes the '*' first item
		foreach ($all as $table) {
			if (!$this->matchTable($table, $tables)) $result[]= $table;
		}
		return $result;
	}
	private function matchTable($table, $list) {
		foreach($list as $item) {
			$item = trim($item);
			if (substr($item, -1) == '*') {
				$prefix = rtrim($item, '*');
				if (substr($table, 0, strlen($prefix)) == $prefix) return true;
			}
			elseif ($table == $item) return true;
		}
		return false;
	}

	//——————————————————————————————————————————————
	// BACKUP

	private function backupDB() {
		$this->lockTables();
		$this->sqlHeader();

		if ($this->show('DATABASE')) {
			$this->sqlComment('DATABASE');
			$this->sqlCreateDB();
		}

		if ($this->show('TABLES')) {
			$this->sqlComment('DROP TABLES');
			foreach ($this->dbtables as $table) $this->sqlDropTable($table);
			$this->append("\n");

			$this->sqlComment('CREATE TABLES');
			foreach ($this->dbtables as $table) $this->sqlCreateTable($table);

			$this->sqlComment('FOREIGN KEYS');
			foreach($this->dbtables as $table) $this->sqlForeignsKeys($table);
		}

		if ($this->show('VIEWS')) {
			$this->sqlComment('VIEWS');
			foreach ($this->dbviews as $view) $this->sqlCreateView($view);
		}

		if ($this->show('PROGRAMS')) {
			$this->sqlComment('PROCEDURES');
			foreach ($this->listProcedures() as $proc) $this->sqlCreateProc($proc);

			$this->sqlComment('FUNCTIONS');
			foreach ($this->listFunctions() as $func) $this->sqlCreateFunc($func);
		}

		if ($this->show('TRIGGERS')) {
			$this->sqlComment('TRIGGERS');
			$this->sqlTriggers();
		}

		if ($this->show('DATA')) {
			$this->sqlComment('TRUNCATE DATA');
			foreach ($this->dbtables as $table) $this->sqlTruncateTable($table);
			$this->append("\n");

			$this->sqlComment('DUMP DATA');
			foreach ($this->dbtables as $table) $this->sqlDumpTable($table);
		}

		$this->sqlFooter();
		$this->unlockTables();
	}

	private function show($item) {
		if (empty($this->show)) return true;
		else return in_array($item, $this->show);
	}

	//——————————————————————————————————————————————
	// LOCK TABLES

	private function lockTables() {
		$all = array_merge($this->dbtables, $this->dbviews);
		$this->pdo->exec('LOCK TABLES `' . implode('` READ, `', $all) . '` READ');
	}
	private function unlockTables() {
		$this->pdo->exec("UNLOCK TABLES");
	}

	//——————————————————————————————————————————————
	// SQL GENERATION

	private $starttime;

	private function sqlComment($text) {
		$comment = "-- $text ";
		$dashes = str_repeat("-", 50-strlen($comment));
		$this->append("$comment$dashes\n\n");
	}

	private function sqlHeader() {
		$this->starttime = microtime(true);
		$database = $this->connection['database'];
		$server = $_SERVER['SERVER_NAME'];
		$datetime = date('Y-m-d H:i:s');
		$this->append(
			"-- BACKUP — $database@$server — $datetime — \n\n".
			"SET NAMES utf8;\n".
			"SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n".
			"SET FOREIGN_KEY_CHECKS = FALSE;\n\n");
	}

	private function sqlFooter() {
		$timediff = microtime(true) - $this->starttime;
		$timediff = self::secondsToTime($timediff);
		$this->sqlComment("ELAPSED $timediff");
		$this->append("SET FOREIGN_KEY_CHECKS = TRUE;\n");
	}
	private static function secondsToTime($sec) {
		$dec = substr(ltrim($sec - floor($sec), '0'), 0, 5);
		$hor = floor($sec / 3600); $sec -= $hor * 3600;
		$min = floor($sec / 60);   $sec -= $min * 60;
		return sprintf('%02d:%02d:%02d%s', $hor, $min, $sec, $dec);
	}

	private function sqlCreateDB() {
		$database = $this->connection['database'];
		$sql = "CREATE DATABASE IF NOT EXISTS $database\n".
			"\tCHARACTER SET utf8\n".
			"\tCOLLATE utf8_general_ci;\n\n".
			"USE $database;\n\n";
		$this->append($sql);
	}

	private function sqlDropTable($table) {
		$this->append("DROP TABLE IF EXISTS `$table`;\n");
	}

	private function sqlCreateTable($table) {
		$result = $this->pdo->query("SHOW CREATE TABLE `$table`");
		$array = $result->fetch(PDO::FETCH_NUM); // Table, Create Table
		$sql = $array[1];
		$sql = substr($sql, 0, 12)." IF NOT EXISTS ".substr($sql, 13);
		$sql = $this->extractForeignKeys($table, $sql);
		$this->append("$sql;\n\n");
	}

	/*
		CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER
			VIEW `vi_aromas` AS select ...
	*/
	private function sqlCreateView($view) {
		$result = $this->pdo->query("SHOW CREATE TABLE `$view`");
		$array = $result->fetch(PDO::FETCH_NUM); // Table, Create Table
		$sql = $array[1];
		if (($pos = strpos($sql, 'VIEW')) !== false) $sql = "CREATE OR REPLACE ".substr($sql, $pos);
		$this->append("$sql;\n\n");
	}

	private function sqlTruncateTable($table) {
		$this->append("TRUNCATE `$table`;\n");
	}

	//——————————————————————————————————————————————
	// FOREIGN KEYS

	private $foreignKeys = array(
		//'table1'=> [ 'ADD CONSTRAINT `name1` FOREIGN KEY(`field1d`) REFERENCES...', ... ],
	);

	private function extractForeignKeys($table, $sqlCreateTable) {
		$lines = explode("\n", $sqlCreateTable);
		$result = array();
		foreach($lines as $line) {
			if (strpos($line, 'FOREIGN KEY') !== false) {
				if (!isset($this->foreignKeys[$table])) $this->foreignKeys[$table] = array();
				$this->foreignKeys[$table][] = "ADD ".rtrim(trim($line),',');
 			}
			else $result[] = $line;
		}
		$result[count($result)-2] = rtrim($result[count($result)-2], ',');
		return implode("\n", $result);
	}

	private function sqlForeignsKeys($table) {
		if (!isset($this->foreignKeys[$table])) return;
		$this->append("ALTER TABLE `$table`\n ");
		$this->append(implode(",\n ", $this->foreignKeys[$table]));
		$this->append(";\n\n");
	}

	//——————————————————————————————————————————————
	// DUMP DATA

	private function sqlDumpTable($table) {
		$limit = self::ROWS_PER_LIMIT;
		for($offset = 0;; $offset += $limit) {
			$sql = "SELECT * FROM `$table` LIMIT $offset, $limit ";
			$result = $this->pdo->query($sql, PDO::FETCH_ASSOC);
			if ($result->rowCount() == 0) return;
			$this->sqlDumpResult($table, $result);
		}
	}
	private function sqlDumpResult($table, $result) {
		$index = $count = 0;
		$COUNT = $result->rowCount();
		$this->writeFile();
		foreach ($result as $row) {
			$count++;
			if ($index++ == 0) $this->sqlInsertTable($table, $row);
			$this->sqlInsertValues($row);
			if ($index >= self::ROWS_PER_INSERT || $count >= $COUNT) {
				$this->append(";\n\n");
				$this->writeFile();
				$index = 0;
			}
			else $this->append(",\n");
		}
		return $count;
	}
	private function sqlInsertTable($table, $row) {
		$fields = array();
		foreach($row as $key=>$value) $fields[] = "`$key`";
		$fields = implode(',', $fields);
		$this->append("INSERT INTO `$table`($fields) VALUES\n");
	}
	private function sqlInsertValues($row) {
		$values = array();
		foreach ($row as $key=>$value) {
			if (isset($value)) {
				$value = addslashes($value); // See PDO::quote()
				$value = str_replace(array("\n","\r"), array('\\n','\\r'), $value);
				$values[] = "'$value'";
			}
			else $values[] = "NULL";
		}
		$this->append(" (".implode(',', $values).")");
	}

	//——————————————————————————————————————————————
	// STORED PROCEDURES AND FUNCTIONS

	private function listProcedures() {
		return $this->listStoredProgram('PROCEDURE');
	}
	private function listFunctions() {
		return $this->listStoredProgram('FUNCTION');
	}
	private function listStoredProgram($TYPE) {
		$database = $this->connection['database'];
		$sql = "SHOW $TYPE STATUS WHERE Db = '$database' AND Type = '$TYPE'";
		$result = $this->pdo->query($sql);
		$list = array();
		foreach($result as $row) $list[] = $row['Name'];
		return $list;
	}

	private function sqlCreateProc($name) {
		$this->sqlCreateStoredProgram($name, 'PROCEDURE');
	}
	private function sqlCreateFunc($name) {
		$this->sqlCreateStoredProgram($name, 'FUNCTION');
	}
	private function sqlCreateStoredProgram($name, $TYPE) {
		//$sql = "SHOW $type CODE $proc"; // Pos, Instruction
		$database = $this->connection['database'];
		$sql = "SHOW CREATE $TYPE `$database`.`$name`";
		$result = $this->pdo->query($sql);
		$fieldname = ucwords(strtolower("create $TYPE"));
		$row = $result->fetch();
		$sql = $row[$fieldname];
		$lines = array(
			'DELIMITER $$',
			"DROP $TYPE IF EXISTS `$name`".'$$',
			$sql.'$$',
			'DELIMITER ;'
		);
		$this->append(implode("\n", $lines)."\n\n");
	}

	//——————————————————————————————————————————————
	// TRIGGERS

	/*
		DELIMITER $$
		DROP TRIGGER IF EXISTS afterInsertGps$$
		CREATE TRIGGER afterInsertGps AFTER INSERT ON map_gps FOR EACH ROW
		BEGIN
		  UPDATE map_locators SET gps_id = NEW.id WHERE id = NEW.locator_id;
		END$$
		DELIMITER ;
	*/
	private function sqlTriggers() {
		$database = $this->connection['database'];
		$sql = "SHOW TRIGGERS FROM $database";
		$result = $this->pdo->query($sql);
		foreach ($result as $row) {
			extract($row); // $Trigger, $Event, $Table, $Statement, $Timing, ...
			$lines = array(
				"DELIMITER ".'$$',
				"DROP TRIGGER IF EXISTS `$Trigger`".'$$',
				"CREATE TRIGGER `$Trigger` $Timing $Event ON `$Table` FOR EACH ROW",
				"$Statement".'$$',
				"DELIMITER ;"
			);
			$this->append(implode("\n", $lines)."\n\n");
		}
	}

} // class
