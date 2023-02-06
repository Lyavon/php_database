<?php

namespace Lyavon\DataBase;

use Lyavon\DataBase\DataBase;

class AutoCommitDecorator
{
    private DataBase $dbh;

    public function __construct(DataBase $dbh)
    {
        $this->dbh = $dbh;
    }

    public function __invoke(callable $c): callable
    {
        return function (mixed ...$args) use ($c): mixed {
            $rc = $c(...$args);
            $this->dbh->commit();
            return $rc;
        };
    }
}
