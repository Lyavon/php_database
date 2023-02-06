<?php

namespace Lyavon\DataBase;

use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use Lyavon\DataBase\DataBase;

class Transaction
{
    use LoggerAwareTrait;

    private DataBase $dbh;

    public function __construct(
        DataBase $dbh,
        LoggerInterface $logger = new NullLogger(),
    )
    {
        $this->dbh = $dbh;
        $this->logger = $logger;
    }

    public function __invoke(callable $c): callable
    {
        return function (mixed ...$args) use ($c): mixed {
            try {
                $rc = $c(...$args);
                $this->dbh->commit();
                return $rc;
            } catch (\Exception $e) {
                $this->logger->error(
                    "Can't commit due to exception {exception}",
                    [
                        'exception' => $e,
                    ],
                );       
                $this->dbh->abort();
                throw $e;
            }
        };
    }
}
