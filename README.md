# DataBase

This repository contains PDO wrapper with ORM-like classes designed to reduce
code on working with databases. The latter is achieved by implicit (or
explicit) database transaction updates and by adding pending SQL queries to
transaction on TableRow object destruction. 

## Installation

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

## Usage

### DataBase
At the core, the __DataBase__ class lies. It is creates identically to the
__PDO__ class and in essence extends it to retain queries until its destruction
or explicit __commit__ call.

The __DataBase__ class implements psr-3 __LoggerAwareTrait__.

Usage example:
```php
<?php

use Lyavon\DataBase\DataBase;
use Lyavon\Logging\StdLogger;

$logger = new StdLogger(LOG_DEBUG);
$dbh = new DataBase('mysql:host=localhost;dbname=test', 'test', 'test');
$dbh->setLogger($logger);

// Code ...

$dbh->commit(); // Or commit implicitly on scope exit
```

### TableRow

__TableRow__ class represents table columns. Before impementing any table
interactions one should declare __TableRow__ extension to operate on.

The implementations is very straightforward: just declare the fields as public
properties, e.g:

```php
<?php

use Lyavon\DataBase\TableRow;


class MyTableRow extends TableRow
{
    public string $id;
    public string $name;
}
```

Default behavior on deletion is ignore. In order to change it there're the following methods:
- ignore
- delete
- update
- insert

### Table

Table in some database is represented by the __Table__ class. By design, Table
is a singleton capable of either implicit row manipulation or explicit _select_
queries.In order to use it one should specialize it by extending. 

```php
<?php

use Lyavon\DataBase\Table;

use MyTableRow;


class MyTable extends Table
{
    // These properties have to be implemented
    public string $insertRowQuery = 'insert into my_table (id, name) values (:id, :name)';
    public string $updateRowQuery = 'update my_table set name = :name where id = :id';
    public string $deleteRowQuery = 'delete from my_table where id = :id';

    // Other behavior, such as clean rows os select should be implemented
    // manually, e.g.:
    public function newRow(string $id, string $name): MyTableRow
    {
        $rc = new MyTableRow($this);
        $rc->id = $id;
        $rc->name = $name;
        $rc->insert();
        return $rc;
    }

    public string $selectByIdQuery = 'select * from my_table where id = :id';

    public function rowById(string $id): MyTableRow|null
    {
        // fetchAll is provided by Table
        $rows = $this->fetchAll(
            $this->selectByIdQuery,
            ['id' => $id],
            MyTableRow::class,
            [$this]
        );
        if (!$rows)
            return null;
        $row = array_shift($rows);
        $row->update();
        return $row;
    }
}

// Other Code ....

$myTable = MyTable::init($dbh); // From previous examples


$newRow1 = $MyTable->newRow('1', '1');
$newRow2 = MyTable::instance()->newRow('2', '2');
$newRow2->ignore();

$oldRow = MyTable::instance()->rowById('3', '3');
$oldRow->delete();

// As the result, after scope exit of all the rows and the DataBase newRow1
// will be added, and oldRow will be deleted.
```

### AutoCommitDecorator

In the unlikely case of a long running PHP script or when several transactions
are needed, __AutoCommitDecorator__ provides mechanism for running code that
generates transaction inside a callable, e.g.:

```php
<?php

use Lyavon\DataBase\AutoCommitDecorator;

$autoCommit = new AutoCommitDecorator($dbh); // $dbh from the previous code
                                             // snipppets

$setName = $autoCommit(function (string $id) {
    $r = MyTable::instance()->rowById($id);
    if ($r->id % 2 == 0)
        $r->name = 'even';
    else
        $r->name = 'odd';
});

$setName('1'); // name of the record with id '1' will be 'odd' after $setName runs
```
