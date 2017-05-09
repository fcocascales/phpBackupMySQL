# php BackupMySQL

Backup a MySQL database only with PHP (without mysqldump)

## Examples

### Example 1

Download a SQL backup file

```php
require_once "BackupMySQL.php";
$backup = new BackupMySQL([
	'host'=> "localhost",
	'database'=> "acme",
	'user'=> "root",
	'password'=> "",
]);
$backup->download();
```
      
### Example 2

Download a ZIP backup file

```php
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
	'TABLES',
	'DATA'
];
$backup = new BackupMySQL($connection, $tables, $show);
$backup->zip();
$backup->download();
```
      
### Example 3

Stores a SQL backup file to a writable folder

```php
require_once "BackupMySQL.php";
$setup = [
	'connection'=> [
		'host'=> "localhost",
		'database'=> "acme",
		'user'=> "root",
		'password'=> "",
	],
	'tables'=> "wp_*,mytable1",
	'show'=> "TABLES,DATA",
	'folder'=> "../backups",
];
$backup = new BackupMySQL();
$backup->setConnection ($setup['connection']);
$backup->setTables ($setup['tables']);
$backup->setShow ($setup['show'])
$backup->setFolder ($setup['folder']);
$backup->run();
```
      
## Changes

TODO:
  - Avoid timeout with database too large

DONE:
  - Extract the FOREIGN KEY of CREATE TABLE sentence.
  - Compress SQL file to ZIP file (without shell)
  - Method to download the SQL or ZIP file (using header)
  - Detect a temporary writable folder to store SQL & ZIP files
  - Delete file after download
  - Publish in GitHub
