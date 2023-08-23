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

use Lyavon\DataBase\DataBase;

/**
 * Transaction provides mechanism for running transactions in case of long
 * running scripts.
 */
class Transaction
{
    use LoggerAwareTrait;

    /**
     * @var DataBase $dbh Database to be used.
     */
    private DataBase $dbh;

    /**
     * Create Transaction.
     *
     * @param Database $dbh Database to be used with.
     * @param LoggerInterface $logger Logger to be used. NullLogger by default.
     */
    public function __construct(
        DataBase $dbh,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->dbh = $dbh;
        $this->logger = $logger;
    }

    /**
     * Create callable that runs transactively.
     *
     * @param callable $c Callable to be run transactively.
     * @return callable
     */
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
