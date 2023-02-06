<?php

namespace Lyavon\DataBase;

use Psr\Log\LoggerAwareTrait;

class DataBase
{
    use LoggerAwareTrait;

    protected \PDO $dbh;
    protected array $statements;

    public function __construct(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        ?array $options = null,
    ) {
        $this->dbh = new \PDO($dsn, $username, $password, $options);
        $this->statements = [];
    }

    public function commit(): bool
    {
        if (!$this->statements)
            return true;

        try {
            $this->dbh->beginTransaction();
            foreach ($this->statements as $statement)
                $statement['query']->execute($statement['values']);
        } catch (\PDOException $e) {
            $this->logger->error(
                "Error during transaction: {exception}",
                [
                    'exception' => $e,
                ],
            );
            $this->dbh->rollBack();
            $this->statements = [];
            return false;
        }
        $this->dbh->commit();
        $this->statements = [];
        return true;
    }

    public function addToTransaction(array $bindedStatement)
    {
        $this->statements[] = $bindedStatement;
    }

    public function __destruct()
    {
        $this->commit();
    }

    public function abort()
    {
        $this->statements = [];
    }

    public function prepare(...$args)
    {
        return $this->dbh->prepare(...$args);
    }
}
