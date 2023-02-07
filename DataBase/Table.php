<?php

namespace Lyavon\DataBase;

use Lyavon\DataBase\CommitAction;
use Lyavon\DataBase\DataBase;

class Table
{
    protected static $_instance;
    protected DataBase $_dataBaseHandler;

    final public function __wakeup()
    {
    }

    private function __clone()
    {
    }

    final private function __construct(DataBase $dataBaseHandler)
    {
        $this->_dataBaseHandler = $dataBaseHandler;
    }

    public static function init(DataBase $dataBaseHandler)
    {
        if (isset(static::$_instance)) {
            throw new \LogicException(
                'Singleton ' . __CLASS__ . ' is already instantiated'
            );
        }
        static::$_instance = new static($dataBaseHandler);
        return static::$_instance;
    }

    final public static function instance()
    {
        if (!isset(static::$_instance)) {
            throw new \LogicException(
                'Singleton ' . __CLASS__ . ' is not instantiated'
            );
        }
        return static::$_instance;
    }

    public string $insertRowQuery = '';
    public string $updateRowQuery = '';
    public string $deleteRowQuery = '';
    public string $selectRowQuery = '';

    public function dataBaseHandler(): DataBase
    {
        return $this->_dataBaseHandler;
    }

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
        }

        $values = [];
        $reflection = new \ReflectionClass($row);
        $attrs = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        foreach ($attrs as $attr) {
            $name = $attr->getName();
            if (strpos($query, $name))
                $values[$name] = $attr->getValue($row);
        }

        $this->_dataBaseHandler->addToTransaction(
            [
            'query' => $this->_dataBaseHandler->prepare($query),
            'values' => $values,
            ],
        );
    }

    public function fetchAll(
        string $query,
        array $parameters,
        string $class,
        array $classArgs,
    ): array {
        $cursor = $this->_dataBaseHandler->prepare($query);
        $cursor->execute($parameters);
        $rc = $cursor->fetchAll(\PDO::FETCH_CLASS, $class, $classArgs);
        $cursor->closeCursor();
        return $rc;
    }
}
