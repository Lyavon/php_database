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

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Lyavon\DataBase\CommitAction;
use Lyavon\DataBase\DataBase;
use Lyavon\DataBase\DataBaseError;

/**
 * Table class represents a table in a database.
 *
 * TODO: add usage description or merge Table with database.
 */
class Table
{
    use LoggerAwareTrait;

    /**
     * @var ?Table $_instance Table class singletone instance.
     */
    protected static ?Table $_instance = null;
    /**
     * @var DataBase $_dataBase Database handler for the Table.
     */
    protected DataBase $_dataBase;

    /**
     * Table is meant to be used as a singleton.
     */
    final public function __wakeup()
    {
    }

    /**
     * Table is meant to be used as a singleton.
     */
    private function __clone()
    {
    }

    /**
     * Table is meant to be used as a singleton.
     *
     * @param DataBase $dataBase Database handler to be associated with.
     * @param LoggerInterface $logger Logger to be used.
     */
    final private function __construct(DataBase $dataBase, LoggerInterface $logger)
    {
        $this->_dataBase = $dataBase;
        $this->setLogger($logger);
    }

    /**
     * Intialize Table instance. Can be called once per script lifetime.
     *
     * @param DataBase $dataBase Database handler to be associated with.
     * @param LoggerInterface $logger Logger to be used. NullLogger by default.
     * @return static
     * @throws DataBaseError on subsequent invocations.
     */
    public static function init(DataBase $dataBase, LoggerInterface $logger = new NullLogger()): static
    {
        if (isset(static::$_instance)) {
            throw new DataBaseError(
                'Singleton ' . get_called_class() . ' is already instantiated',
            );
        }
        return static::$_instance = new static($dataBase, $logger);
    }

    /**
     * Obtain Table instance.
     *
     * @return static
     * @throws DataBaseError on invocation before init.
     */
    final public static function instance(): static
    {
        if (!isset(static::$_instance)) {
            throw new DataBaseError(
                'Singleton ' . get_called_class() . ' is not initialized',
            );
        }
        return static::$_instance;
    }

    /**
     * @var string $insertRowQuery SQL query for new row insertion.
     */
    public string $insertRowQuery = '';
    /**
     * @var string $updateRowQuery SQL query for particular row update.
     */
    public string $updateRowQuery = '';
    /**
     * @var string $deleteRowQuery SQL query for particular row deletion.
     */
    public string $deleteRowQuery = '';

    /**
     * Obtain underlying DataBase.
     *
     * @return DataBase
     */
    public function dataBase(): DataBase
    {
        return $this->_dataBase;
    }

    /**
     * Dispatch the given row to the database.
     *
     * @param mixed $row Row to be dispatched.
     * @return void
     * @throws DataBaseError on error during query preparation.
     */
    public function dispatch(mixed $row): void
    {
        $query = '';
        switch ($row->getAction()) {
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
        $reflection = new \ReflectionClass($row);
        $attrs = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($attrs as $attr) {
            $name = $attr->getName();
            if (strpos($query, $name)) {
                $values[$name] = $attr->getValue($row);
            }
        }

        try {
            $this->_dataBase->addToTransaction(
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
            throw new DataBaseError( "Can't prepare statement", 0, $e);
        }
    }

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
    public function fetchAll(
        string $query,
        array $parameters,
        string $class,
        array $classArgs,
    ): array {
        try {
            $cursor = $this->_dataBase->prepare($query);
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
}
