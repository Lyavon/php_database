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
 * TableRow is a class representing single row of a corresponding table.
 *
 * TODO: describe usage.
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
     * @return void
     */
    public function ignore(): void
    {
        $this->_action = CommitAction::Ignore;
    }

    /**
     * Set commit action to insert.
     * @return void
     */
    public function insert(): void
    {
        $this->_action = CommitAction::Insert;
    }

    /**
     * Set commit action to update.
     * @return void
     */
    public function update(): void
    {
        $this->_action = CommitAction::Update;
    }

    /**
     * Set commit action to delete.
     * @return void
     */
    public function delete(): void
    {
        $this->_action = CommitAction::Delete;
    }

    /**
     * Get current commit action for the TableRow.
     *
     * @return CommitAction
     */
    public function action(): CommitAction
    {
        return $this->_action;
    }

    /**
     * TableRow instance peforms database insteraction only on destruction.
     */
    public function __destruct()
    {
        $query = '';
        switch ($this->action()) {
            case CommitAction::Delete:
                $query = $this->deleteRowQuery;
                break;
            case CommitAction::Update:
                $query = $this->updateRowQuery;
                break;
            case CommitAction::Insert:
                $query = $this->insertRowQuery;
                break;
            default:
                return;
        }

        $values = [];
        $reflection = new \ReflectionClass(static);
        $attrs = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($attrs as $attr) {
            $name = $attr->getName();
            if (strpos($query, $name)) {
                $values[$name] = $attr->getValue($row);
            }
        }

        try {
            static::$dataBase->addToTransaction(
                [
                'query' => $this->_dataBase->prepare($query),
                'values' => $values,
                ],
            );
        } catch (\Throwable $th) {
            $this->logger->error(
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
     * @var ?DataBase $dataBase Database associated with TableRow.
     */
    protected static ?DataBase $dataBase = null;

    /**
     * @var LoggerInterface $logger Logger associated with TableRow. NullLogger by default.
     */
    protected static LoggerInterface $logger = new NullLogger();

    /**
     * Initialize the TableRow subclass.
     *
     * @param DataBase $database DataBase to be used.
     * @param LoggerInterface $logger Logger to be used. NullLogger by default.
     *
     */
    public static function init(DataBase $database, ?LoggerInterface $logger): void
    {
        static::$database = $database;
        if ($logger)
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
     * @param string $query
     * @param array $parameters
     * @param string $class
     * @param array $classArgs
     * @return array
     * @throws DataBaseError on any error occured during database interaction.
     */
    public static function fetchAll(
        string $query,
        array $parameters,
        string $class,
        array $classArgs,
    ): array {
        try {
            $cursor = static::$dataBase->prepare($query);
            $cursor->execute($parameters);
            $rc = $cursor->fetchAll(\PDO::FETCH_CLASS, $class, $classArgs);
            $cursor->closeCursor();
            return $rc;
        } catch (\Throwable $th) {
            $this->logger->error(
                "Can't fetch ({query}) with ({parameters}): {throwable}",
                [
                    'query' => $query,
                    'parameters' => $parameters,
                    'throwable' => $th,
                ],
            );
            throw new DataBaseError("Can't query database", 0, $e);
        }
    }

    /**
     * Create versioning table for all the tables for the database.
     *
     * @return void
     * @throws DataBaseError on error during table creaction.
     */
    protected static function createVersioningTable(): void {
        try {
            $cursor = static::$dataBase->prepare('
                CREATE TABLE IF NOT EXISTS
                    tables_versions
                (
                    name TINYTEXT PRIMARY KEY,
                    version SMALLINT UNSIGNED DEFAULT 0
                )
            ');
            $cursor->execute();
            $cursor->closeCursor();
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
     * @return int|null
     * @throws DataBaseError on error during database transaction.
     */
    protected static function getCurrentTableVersion(string $tableName): int|null {
        try{
            $cursor = static::$database->prepare('
                SELECT
                    version 
                FROM
                    tables_versions
                WHERE
                    name = :name
            ');
            $cursor->execute([
                'name' => $tableName,
            ]);
            $result = $cursor->fetchAll();
            return $result ? $result[0]['version']: null;
        } catch (\Throwable $th) {
            static::$database->alert(
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
     * @param string $tableName Name of the table.
     * @param int $version Tables's actual version.
     * @return void
     * @throws DataBaseError on error during database transaction.
     */
    protected static function setCurrentTableVerstion(string $tableName, int $version): void {
        try {
            $cursor = static::$database->prepare('
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
            $cursor->execute([
                'name' => $tableName,
                'version' => $version,
            ]);
            $result = $cursor->fetchAll();
        } catch (\Throwable $th) {
            static::$database->alert(
                "Can't set table version for {name} ({version}): {cause}",
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
