<?php
/* Copyright 2023 Leonid Ragunovich
 *
 * This file is part of php_database.
 *
 * php_database is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program (see LICENSE file in parent directory). If not, see
 * <https://www.gnu.org/licenses/>.
 */

namespace Lyavon\DataBase;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Lyavon\DataBase\CommitAction;
use Lyavon\DataBase\DataBase;

/**
 * TableRow is a class that represents both single table row and the whole
 * table.
 *
 * TableRow strives to allow interacting with database in an ORM-like manner
 * while retaining maximal control over database by using SQL for all the
 * operations. By default static methods and properties regulate table behavior
 * as a whole while instance methods affect only a single row.
 *
 * The typical usage of TableRow is subclassing it with providing all the
 * necessary operations. Subclass must define the following:
 * - Properties that will be accessed from PHP.
 * - {@see \Lyavon\DataBase\TableRow::$deleteRowQuery deleteRowQuery}.
 * - {@see \Lyavon\DataBase\TableRow::$insertRowQuery insertRowQuery}.
 * - {@see \Lyavon\DataBase\TableRow::$updateRowQuery updateRowQuery}.
 * - {@see \Lyavon\DataBase\TableRow::migrate() migrate}.
 * - Additional functions (new object creation, required selections etc).
 *
 * {@see \Lyavon\DataBase\TableRow::init() Initialization} have to take place on
 * script startup.
 *
 * Query SQL strings are used for default delete, update and insert actions
 * that happen on {@see \Lyavon\DataBase\TableRow::__destruct() instance
 * deletion}. A particular action is set up with the corresponding methods:
 * - {@see \Lyavon\DataBase\TableRow::delete() delete}
 * - {@see \Lyavon\DataBase\TableRow::ignore() ignore}
 * - {@see \Lyavon\DataBase\TableRow::insert() insert}
 * - {@see \Lyavon\DataBase\TableRow::update() update}
 * - And can be queried by {@see \Lyavon\DataBase\TableRow::action() action}.
 *
 * TableRow attempts to implement migration in IndexedDB manner, which means
 * tracking table version (integer) and incrementing it each time change
 * happens. Before table creation its version is null then it is expected to go
 * from zero up to 2^16, incrementing after each change. Although programmer
 * might implement his own integer sequence.
 * {@see \Lyavon\DataBase\TableRow::createVersioningTable()
 * createVersioningTable}, {@see
 * \Lyavon\DataBase\TableRow::getCurrentTableVersion() getCurrentTableVersion},
 * {@see \Lyavon\DataBase\TableRow::setCurrentTableVersion()
 * setCurrentTableVersion} are helpers for migration implementation.
 *
 * __N.B.! Migration helpers may not play well with DataBase transactional
 * mechanism.__
 *
 * Lion share of database interactions may be done with {@see
 * \Lyavon\DataBase\TableRow::fetchAll() fetchAll} method. The rest might be done
 * by obtaining _\\PDO_ handler from the used database.
 *
 * TableRow supports PSR-3 compatible logger and uses NullLogger by default.
 */
abstract class TableRow
{
    /**
     * @var CommitAction $_action Action to be performed on object deletion
     * (Ignore by default).
     */
    protected CommitAction $_action = CommitAction::Ignore;

    /**
     * Set commit action to ignore.
     */
    public function ignore(): void
    {
        $this->_action = CommitAction::Ignore;
    }

    /**
     * Set commit action to insert.
     */
    public function insert(): void
    {
        $this->_action = CommitAction::Insert;
    }

    /**
     * Set commit action to update.
     */
    public function update(): void
    {
        $this->_action = CommitAction::Update;
    }

    /**
     * Set commit action to delete.
     */
    public function delete(): void
    {
        $this->_action = CommitAction::Delete;
    }

    /**
     * Get current commit action for the TableRow.
     *
     * @return CommitAction Current action that is set for a row.
     */
    public function action(): CommitAction
    {
        return $this->_action;
    }

    /**
     * TableRow instance peforms database interaction only on destruction.
     */
    public function __destruct()
    {
        $query = '';
        switch ($this->action()) {
            case CommitAction::Delete:
                $query = static::$deleteRowQuery;
                break;
            case CommitAction::Update:
                $query = static::$updateRowQuery;
                break;
            case CommitAction::Insert:
                $query = static::$insertRowQuery;
                break;
            default:
                return;
        }

        $values = [];
        $reflection = new \ReflectionClass(get_called_class());
        $attrs = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($attrs as $attr) {
            $name = $attr->getName();
            if (strpos($query, $name)) {
                $values[$name] = $attr->getValue($this);
            }
        }

        try {
            static::$dataBase->addToTransaction(
                $query,
                $values,
            );
        } catch (\Throwable $th) {
            static::$logger->error(
                "Can't prepare query ({query}) with ({values}): {throwable}",
                [
                    'query' => $query,
                    'values' => $values,
                    'throwable' => $th,
                ],
            );
        }
    }

    /**
     * @var DataBase $dataBase Database associated with TableRow.
     */
    protected static DataBase $dataBase;
    /**
     * @var LoggerInterface $logger Logger associated with TableRow. NullLogger by default.
     */
    protected static LoggerInterface $logger;

    /**
     * Initialize the TableRow subclass.
     *
     * @param DataBase $dataBase DataBase to be used.
     * @param LoggerInterface $logger Logger to be used. NullLogger by default.
     *
     */
    public static function init(DataBase $dataBase, LoggerInterface $logger = new NullLogger()): void
    {
        static::$dataBase = $dataBase;
        static::$logger = $logger;
    }

    /**
     * @var string $insertRowQuery SQL query for new row insertion.
     */
    protected static string $insertRowQuery;
    /**
     * @var string $updateRowQuery SQL query for particular row update.
     */
    protected static string $updateRowQuery;
    /**
     * @var string $deleteRowQuery SQL query for particular row deletion.
     */
    protected static string $deleteRowQuery;

    /**
     * Fetch all suitable rows according to query parameters.
     *
     * @param string|\PDOStatement $statement Prepared or unprepared statement
     * for fetch.
     * @param array $statementArgs Arguments for sustitution for the statement.
     * Empty array by default.
     * @param array $prepareOptions Options for preparation in case of
     * unprepared statement. Empty array by default.
     * @param int $mode Fetch mode. \PDO::FETCH_CLASS by default. Supports only
     * FETCH_CLASS and FETCH_DEFAULT.
     * @param ?string $class Class for fetched result. get_called_class() by
     * default.
     * @param ?array $classArgs Arguments for the result class instantiation.
     * Empty array by default.
     * @return array Array of fetched entries for a given query depending on
     * the provided _$mode_.
     * @throws DataBaseError on any error occured during database interaction.
     */
    public static function fetchAll(
        string|\PDOStatement $statement,
        array $statementArgs = [],
        array $prepareOptions = [],
        int $mode = \PDO::FETCH_CLASS,
        ?string $class = null,
        ?array $classArgs = [],
    ): array {
        try {
            $class ??= get_called_class();
            if (is_string($statement)) {
                $statement = static::$dataBase->prepare($statement, $prepareOptions);
            }
            $statement->execute($statementArgs);
            $result = $mode == \PDO::FETCH_CLASS
                ? $statement->fetchAll(\PDO::FETCH_CLASS, $class, $classArgs)
                : $statement->fetchAll(\PDO::FETCH_DEFAULT)
            ;
            $statement->closeCursor();
            return $result;
        } catch (\Throwable $th) {
            static::$logger->error(
                "Can't fetch ({statement}) with ({statementArgs}): {throwable}",
                [
                    'statement' => $statement,
                    'statementArgs' => $statementArgs,
                    'throwable' => $th,
                ],
            );
            throw new DataBaseError("Can't query database", 0, $e);
        }
    }

    /**
     * Create versioning table for all the tables for the database.
     *
     * __N.B.! This operation breaks DataBase transaction.__
     *
     * @throws DataBaseError on error during table creaction.
     */
    protected static function createVersioningTable(): void
    {
        try {
            $cursor = static::$dataBase->addToTransaction(
                '
                    CREATE TABLE IF NOT EXISTS
                        tables_versions
                    (
                        name VARCHAR(255) PRIMARY KEY,
                        version SMALLINT UNSIGNED DEFAULT 0
                    )
                ',
            );
        } catch (\Throwable $th) {
            static::$logger->alert(
                "Can't create versioning table: {reason}",
                [
                    'reason' => $th,
                ],
            );
            throw new DataBaseError("Can't create versioning table", 0, $th);
        }
    }

    /**
     * Obtain current version for the table.
     *
     * @param string $tableName Name of the table.
     * @return int|null Table's version inside the database or null if it
     * doesn't exist yet.
     * @throws DataBaseError on error during database transaction.
     */
    protected static function getCurrentTableVersion(string $tableName): int|null
    {
        try {
            static::$dataBase->commit();
            $version = static::fetchAll(
                '
                    SELECT
                        version
                    FROM
                        tables_versions
                    WHERE
                        name = :name
                ',
                [
                    'name' => $tableName,
                ],
                [
                ],
                \PDO::FETCH_DEFAULT,
            );
            return $version ? $version[0]['version'] : null;
        } catch (\Throwable $th) {
            static::$logger->alert(
                "Can't fetch table version for {name}: {cause}",
                [
                    'name' => $tableName,
                    'cause' => $th,
                ],
            );
            throw new DataBaseError("Can't fetch table version for " . $tableName, 0, $th);
        }
    }

    /**
     * Set current version for the table.
     *
     * __N.B.! Change is not applied until commit happens.__
     *
     * @param string $tableName Name of the table.
     * @param int $version Tables's actual version.
     * @throws DataBaseError on error during database transaction.
     */
    protected static function setCurrentTableVersion(string $tableName, int $version): void
    {
        try {
            static::$dataBase->addToTransaction(
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
                    'name' => $tableName,
                    'version' => $version,
                ]
            );
        } catch (\Throwable $th) {
            static::$logger->alert(
                "Can't create transaction to set table {name} version to {version}: {cause}",
                [
                    'name' => $tableName,
                    'version' => $version,
                    'cause' => $th,
                ],
            );
            throw new DataBaseError("Can't set table version for " . $tableName, 0, $th);
        }
    }

    /**
     * Perform database migration to a newer version.
     */
    abstract public static function migrate(): void;
}
