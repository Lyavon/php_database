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
        if (!$this->statements) {
            return true;
        }

        $rc = $this->dbh->beginTransaction();
        if (!$rc) {
            $this->logger->error(
                "Can't begin transaction",
            );
            return false;
        }

        foreach ($this->statements as $statement) {
            if ($statement['query']->execute($statement['values'])) {
                continue;
            }
            $this->logger->error(
                "Can't execute ({statement}) ({error})",
                [
                  'statement' => $statement->queryString,
                  'error' => print_r($statement->errorInfo(), true),
                ],
            );
            if (!$this->dbh->rollBack()) {
                $this->logger->error(
                    "Can't roll back the transaction ({error})",
                    [
                      'error' => $this->dbh->errorCode(),
                    ],
                );
            }
            return false;
        }

        if (!$this->dbh->commit()) {
            $this->logger->error(
                "Can't commit the transaction ({error})",
                [
                  'error' => $this->sbh->errorCode(),
                ],
            );
            return false;
        }
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

    public function prepare(...$args)
    {
        return $this->dbh->prepare(...$args);
    }
}
