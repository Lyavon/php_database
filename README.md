# DataBase

php\_database is an attempt to create ORM-like system that is simultaneously
lightweight and gives complete control over database by using SQL queries.

This goals are achieved by:
- Database wrapper that:
    - Automates statements preparation.
    - Automates statements aggregation into transactions.
    - Allows firing transactions both implicitly and on demand.
    - Allows storing prepared statements for speedup.
    - Gives access to underlying PDO handler.
    - Compatible with PSR-3 loggers.
- TableRow wrapper that:
    - Automates filling statements' arguments.
    - Automates applying typical database changes.
    - Organizes migrations in IndexedDB manner.
    - Compatible with PSR-3 loggers.

# Installation

Add the following entries to the __composer.json__:
```json
"require": {
    "lyavon/database": "dev-master"
},
"repositories": [
    {
        "url": "https://github.com/Lyavon/php_database.git",
        "type": "vcs"
    }
],
```

# Usage

## DataBase

__DataBase__ is a wrapper that:
- Automates statements preparation.
- Automates statements aggregation into transactions.
- Allows firing transactions both implicitly and on demand.
- Allows storing prepared statements for speedup.
- Gives access to underlying PDO handler.
- Compatible with PSR-3 loggers.

Detailed description may be found as PHPDoc comment at DataBase/DataBase.php.

Usage example with verbose commentaries:
```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Lyavon\DataBase\DataBase;

# For output purposes. Any PSR-3 compatible logger would suffice.
# See https://github.com/Lyavon/php_logging for more info.
use Lyavon\Logging\StdLogger;

$logger = new StdLogger(LOG_ERROR); # Supress irrelevant logs.
$db = new DataBase(
    'mysql:host=localhost;dbname=test', # DSN string
    'test', # Username
    'test', # Password
    [], # Optional PDO parameters. Sane defaults will be set anyway. See PHPDoc
        # for more info.
    $logger, # NullLogger will be used by default. setLogger method is also
             # supported.
);

# Adding unprepared query without arguments.
$db->addToTransaction('
    CREATE TABLE IF NOT EXISTS
        tables_versions
    (
        name VARCHAR(255) PRIMARY KEY,
        version SMALLINT UNSIGNED DEFAULT 0
    )
');

# Adding unprepared query with arguments.
$db->addToTransaction(
    '
        INSERT INTO
            tables_versions
        (
            name,
            version
        )
        VALUES
        (
            :name,
            :version
        )
        ON
            DUPLICATE KEY
        UPDATE
            version = :version
    ',
    [
        'name' => 'unprepared',
        'version' => 1,
    ]
);

# Preparing query (suppose for speedup reasons).
# Prepare options might be passed as second argument.
$statement = $db->prepare('
    INSERT INTO
        tables_versions
    (
        name,
        version
    )
    VALUES
    (
        :name,
        :version
    )
    ON
        DUPLICATE KEY
    UPDATE
        version = :version
');
# Using prepared statement.
$db->addToTransaction(
    $statement,
    [
        'name' => 'prepared',
        'version' => 2,
    ]
);

# Forcing transaction to run.
# $db->abort(); might be used to clear statements queue.
# Implicit commit will run on $db deletion (e.g. scope exit).
$db->commit();

# Obtaining PDO handler.
$handler = $db->handler();
```

## TableRow

__TableRow__ plays role of manager of both database table and database table
row. Its static functionality defines database table behavior while instance
properties and methods represent single database table row.

__TableRow's__ features are:
- Automation of filling statements' arguments.
- Automation of applying typical database changes.
- Organization of migrations in IndexedDB manner.
- Compatibility with PSR-3 loggers.

__TableRow__ usage requires subclassing with adding in the necessary
functionality. Exaple below provides simple example how to do it. Please, refer
to PHPDoc comments at DataBase/TableRow.php for more info.

```php
<?php

require __DIR__ . '/vendor/autoload.php';

# DataBase and StdLogger are introduced at the DataBase section.
use Lyavon\DataBase\DataBase;
use Lyavon\DataBase\TableRow;
use Lyavon\Logging\StdLogger;

# Subclassing TableRow. First version.
class TestTableRow extends TableRow
{
# Columns that will be accessible from PHP. They have to in sync with SQL
# queries.
    public int $id;
    public string $name;

# The following queries are used for corresponding automatic insertion, update
# and delete actions.
    protected static string $insertRowQuery = '
    INSERT INTO test_table
    (id, name)
    VALUES (:id, :name)
  ';
    protected static string $updateRowQuery = '
    UPDATE test_table
    SET name = :name
    WHERE id = :id
  ';
    protected static string $deleteRowQuery = '
    DELETE FROM test_table
    WHERE id = :id
  ';

# migrate function has to be implemented in order to support migrations.
# Migrations are desined in IndexedDB style, which means that each change ought
# to have incremented version number. TableRow provides createVersioningTable,
# getCurrentTableVersion and setCurrentTableVersion for version management. See
# PHPDoc comments for more info.
    public static function migrate(): void
    {
# At the first run versioning table might not be created yet. 
        static::createVersioningTable();
        $version = static::getCurrentTableVersion('test_table');

# At the first Table migration there's no version (null).
        if ($version === null) {
# fetchAll is a somewhat hybrid between DataBase::addToTransaction and
# \PDOStatement::fetchAll that gives ability to run SQL queries similar to
# DataBase but allows to obtain results as objects or as arrays. See PHPDoc
# comments for much more info. More specific actions may be done by PDO handler
# that was introduced in the DataBase section.
            static::fetchAll('
                CREATE TABLE test_table
                (
                    id INT PRIMARY KEY,
                    name TINYTEXT NOT NULL
                )
            ');
            $version = 0;
        }
# My preferred way to achieve consecutive chaining of versions application.
        switch ($version) {
            default:
                static::setCurrentTableVersion('test_table', $version);
# Commit is required if migration is not the last action of a script.
                static::$dataBase->commit();
        }
    }

# Example of function that allows creating new rows from PHP.
    public static function new(
        string $id,
        string $name,
    ): TestTableRow {
        $result = new TestTableRow();
        $result->id = $id;
        $result->name = $name;
# Action on instance deletion. Other options are ignore, update, delete.
        $result->insert();
        return $result;
    }

# Example of a search query.
    public static function byId(int $id): TestTableRow|null
    {
        $row = static::fetchAll(
            '
                SELECT *
                FROM test_table
                WHERE id = :id
            ',
            [
               'id' => $id,
            ],
        );
        if (!$row) {
            return null;
        }
        $row = array_shift($row);
        $row->update();
        return $row;
    }
}

# Minimal example of TestTableRow usage.

$logger = new StdLogger(LOG_ERROR);
$dbh = new DataBase(
    'mysql:host=localhost;dbname=test1',
    'test',
    'test',
    [],
    $logger
);
TestTableRow::init($dbh, $logger);
TestTableRow::migrate();

$t1 = TestTableRow::byId(1);
if (!$t1) {
    $t1 = TestTableRow::new(1, 'alice');
}

$t2 = TestTableRow::new(2, 'bob');
# Example of action change.
$t2->ignore();

# Otherwise $t11 would be null. No transaction would take place until script
# finishes.
unset($t1);
$dbh->commit();

$t11 = TestTableRow::byId(1);
var_dump($t11);
$t21 = TestTableRow::byId(2);
var_dump($t21);
```

Updated script gives the following results:

```
Script output:

object(TestTableRow)#13 (3) {
  ["_action":protected]=>
  enum(Lyavon\DataBase\CommitAction::Update)
  ["id"]=>
  int(1)
  ["name"]=>
  string(5) "alice"
}
NULL


Database contents:

select * from tables_versions; -- Current test_table version is 0.
+------------+---------+
| name       | version |
+------------+---------+
| test_table |       0 |
+------------+---------+

select * from test_table; -- Bob were ignored. Alice has been successfully saved.
+----+-------+
| id | name  |
+----+-------+
|  1 | alice |
+----+-------+
```

Suppose after some time we want to improve our database by adding optional
_alias_ column. __TableRow__ allows to do that in rather simple manner (below
will be only TestTableRow with changes, the rest of the script is unchanged):

```php
class TestTableRow extends TableRow
{
    public int $id;
    public string $name;
    public ?string $alias; # Added

# Queries are updated as well
    protected static string $insertRowQuery = '
    INSERT INTO test_table
    (id, name, alias)
    VALUES (:id, :name, :alias)
  ';
    protected static string $updateRowQuery = '
    UPDATE test_table
    SET name = :name, alias = :alias
    WHERE id = :id
  ';
    protected static string $deleteRowQuery = '
    DELETE FROM test_table
    WHERE id = :id
  ';

    public static function migrate(): void
    {
        static::createVersioningTable();
        $version = static::getCurrentTableVersion('test_table');

        if ($version === null) {
            static::fetchAll('
                CREATE TABLE test_table
                (
                    id INT PRIMARY KEY,
                    name TINYTEXT NOT NULL
                )
            ');
            $version = 0;
        }
        switch ($version) {
            case 0: # Added section
                static::$dataBase->addToTransaction('
                    ALTER TABLE test_table
                    ADD COLUMN alias TINYTEXT NULL
                ');
                $version++;
            default:
                static::setCurrentTableVersion('test_table', $version);
                static::$dataBase->commit();
        }
    }

    public static function new(
        string $id,
        string $name,
        ?string $alias = null, # Added
    ): TestTableRow {
        $result = new TestTableRow();
        $result->id = $id;
        $result->name = $name;
        $result->alias = $alias; # Added
        $result->insert();
        return $result;
    }

    public static function byId(int $id): TestTableRow|null
    {
        $row = static::fetchAll(
            '
                SELECT *
                FROM test_table
                WHERE id = :id
            ',
            [
               'id' => $id,
            ],
        );
        if (!$row) {
            return null;
        }
        $row = array_shift($row);
        $row->update();
        return $row;
    }
}
```

Running updates script gives the following results:

```
Script output:

object(TestTableRow)#12 (4) {
  ["_action":protected]=>
  enum(Lyavon\DataBase\CommitAction::Update)
  ["id"]=>
  int(1)
  ["name"]=>
  string(5) "alice"
  ["alias"]=>
  NULL
}
NULL


Database contents:

select * from tables_versions; -- version is bumped to 1.
+------------+---------+
| name       | version |
+------------+---------+
| test_table |       1 |
+------------+---------+

select * from test_table; -- alias is now applied.
+----+-------+-------+
| id | name  | alias |
+----+-------+-------+
|  1 | alice | NULL  |
+----+-------+-------+

```

## Scripts usage
php\_database provides several scripts for convenience:

```sh
# Generate phpdoc (output is going to _documentation_ directory of project
# root. Expects phpdoc to be installed globally).
sh scripts/documentation.sh
# or
composer run-script documentation

# Show documentation (opens in the default browser. Will try to run
# _documentation_ if it has not been done yet).
sh scripts/show-documentation.sh
# or
composer run-script show-documentation

# Fix codestyle (Expects php-cs-fixer to be installed globally).
sh scripts/codestyle.sh
# or
composer run-script codestyle
```

# License
This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with
this program (see LICENSE file in this directory). If not, see
<https://www.gnu.org/licenses/>.
