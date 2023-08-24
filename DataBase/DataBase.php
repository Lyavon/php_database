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

use Lyavon\DataBase\DataBaseError;

/**
 * DataBase class is a wrapper around PDO extension that simplifies usage of
 * the latter, especially in a transactional manner.
 *
 * Simplification involves _\\PDO_ initialization, conversion of SQL query
 * string into _\\PDOStatement_, and aggregation of statements in order to run
 * them as a transaction. The rest of PDO functionality is available through
 * {@link \Lyavon\DataBase\DataBase::handler _\\PDO_}.
 *
 * DataBase initialization sets the following options to true:
 * - _\\PDO::ATTR_PERSISTENT_ (by default)
 * - _\\PDO::NULL_NATURAL_ (always)
 * - _\\PDO::CASE_NATURAL_ (always)
 * - _\\PDO::ERRMODE_EXCEPTION_ (always)
 * Safe DSN, username and password obtainment is still up to the programmer.
 *
 * {@link \Lyavon\DataBase\DataBase::addToTransaction Adding to transaction}
 * may be done in two forms: prepared or unprepared statements. In case of
 * unprepared statement preparation is made on the fly which simplifies code.
 * On the other hand, {@link \Lyavon\DataBase\DataBase::prepare preparation}
 * alongside with caching the statement might allow to skip this operation in
 * case of a long running script.
 *
 * All the statements are eventually get aggregated into _$statements_ array
 * via _addToTransaction_. After that transaction may either be {@link
 * \Lyavon\DataBase\DataBase::abort aborted on user demand}, {@link
 * \Lyavon\DataBase\DataBase::commit commited explicitly}, or {@link
 * \Lyavon\DataBase\DataBase::__destruct commited implicitly on scope exit}.
 *
 * __N.B.! Some statements might be implicitly autocommited (such as creating
 * or dropping tables). Therefore it's better to commit them separated from the
 * rest of the transaction.__
 *
 * DataBase supports PSR-3 compatible loggers. _NullLogger_ is used by default.
 */
class DataBase
{
    /**
     * Used to provide debug and error info about DataBase execution.
     */
    use LoggerAwareTrait;

    /**
     * @var \PDO $dbh Original PDO handler.
     */
    protected \PDO $dbh;
    /**
     * @var array $statements Statements prepared for transaction.
     */
    protected array $statements;

    /**
     * Construct DataBase.
     *
     * @param ?string $dsn Data Source Name for the current DataBase instance.
     * Optional (dsn will be fetched from the environment then).
     * @param ?string $username Username for the provided DSN. Optional.
     * @param ?string $password Password for the provided DNs. Optional.
     * @param array $options PDO options to use. NULL_NATURAL, CASE_NATURAL,
     * ERRMODE_EXCEPTION will always be true. ATTR_PERSISTENT will be true by
     * default.
     * @throws DataBaseError If unable to connect to the dabase.
     */
    public function __construct(
        ?string $dsn = null,
        ?string $username = null,
        ?string $password = null,
        array $options = [],
        LoggerInterface $logger = new NullLogger(),
    ) {
        try {
            if (!array_key_exists(\PDO::ATTR_PERSISTENT, $options)) {
                $options[\PDO::ATTR_PERSISTENT] = true;
            }
            $options[\PDO::NULL_NATURAL] = true;
            $options[\PDO::CASE_NATURAL] = true;
            $options[\PDO::ERRMODE_EXCEPTION] = true;
            $this->dbh = new \PDO($dsn, $username, $password, $options);
            $this->statements = [];
            $this->logger = $logger;
            $this->logger->debug(
                "<{id}> Connect to {dsn} with {username} ({password}). Used options: {options}",
                [
                    'id' => spl_object_id($this->dbh),
                    'dsn' => $dsn,
                    'username' => $username,
                    'password' => $password,
                    'options' => $options,
                ],
            );
        } catch (\Throwable $th) {
            $this->logger->emergency(
                "Can't connect to {dsn} with {username} ({password}): {exception}. Used options: {options}",
                [
                    'dsn' => $dsn,
                    'username' => $username,
                    'password' => $password,
                    'exception' => $th,
                    'options' => $options,
                ],
            );
            throw new DataBaseError("Can't connect to the database", 0, $th);
        }
    }

    /**
     * Prepare statement.
     *
     * @param string $query SQL query.
     * @param array $options Arguments for the query. Optional.
     * @return \PDOStatement
     * @throws DataBaseError on invalid arguments provided.
     */
    public function prepare(string $query, array $options = []): \PDOStatement
    {
        try {
            return $this->dbh->prepare($query, $options);
        } catch (\Throwable $th) {
            $this->logger->error(
                "<{id}> Error during preparation of ({query}) ({options}): {throwable}",
                [
                    'id' => spl_object_id($this->dbh),
                    'query' => $query,
                    'options' => $options,
                    'throwable' => $th
                ],
            );
            throw new DataBaseError("Can't prepare query", 0, $th);
        }
    }

    /**
     * Add new statement to pending transaction.
     *
     * Statement might be either prepared or not. In case of prepared
     * statement, $prepareOptions will be ignored.
     *
     * @param string|\PDOStatement $statement Prepared or unprepared statement
     * for transaction.
     * @param array $statementArgs Arguments for sustitution for the statement.
     * Empty array by default.
     * @param array $prepareOptions Options for preparation in case of
     * unprepared statement. Empty array by default.
     * @return void
     * @throws DataBaseError on error during preparation.
     */
    public function addToTransaction(
        string|\PDOStatement $statement,
        array $statementArgs = [],
        array $prepareOptions = [],
    ): void {
        if (is_string($statement)) {
            $statement = $this->prepare($statement, $prepareOptions);
        }
        $statement = [
            'query' => $statement,
            'values' => $statementArgs,
        ];
        $this->statements[] = $statement;
        $this->logger->debug(
            "<{id}> Add to DataBase transaction: {statement}",
            [
                'id' => spl_object_id($this->dbh),
                'statement' => $statement,
            ],
        );
    }

    /**
     * Abort the transaction by removing all the pending statements.
     *
     * @return void
     */
    public function abort(): void
    {
        $this->statements = [];
        $this->logger->debug(
            "<{id}> Abort DataBase transaction",
            [
                'id' => spl_object_id($this->dbh),
            ],
        );
    }

    /**
     * Execute pending statements in a transaction.
     *
     * N.B.! Some statements might be implicitly autocommited (such as creating
     * or dropping tables). Therefore it's better to commit them separated from
     * the rest of the transaction.
     *
     * @return void
     * @throws DataBaseError on error during transaction.
     */
    public function commit(): void
    {
        if (!$this->statements) {
            $this->logger->debug(
                "<{id}> Empty DataBase transaction commit",
                [
                    'id' => spl_object_id($this->dbh),
                ],
            );
            return;
        }

        try {
            $this->dbh->beginTransaction();
            foreach ($this->statements as $statement) {
                $statement['query']->execute($statement['values']);
            }
            // Some statements might autocommit implicitly.
            if ($this->dbh->inTransaction()) {
                $this->dbh->commit();
            }
            $this->logger->debug(
                "<{id}> Successful DataBase transaction commit",
                [
                    'id' => spl_object_id($this->dbh),
                ],
            );
        } catch (\PDOException $e) {
            $this->logger->error(
                "<{id}> Error during transaction of statement ({statement}): {exception}",
                [
                    'exception' => $e,
                    'id' => spl_object_id($this->dbh),
                    'statement' => $statement,
                ],
            );
            // Some statements might autocommit implicitly.
            if ($this->dbh->inTransaction()) {
                $this->dbh->rollBack();
            }
            $this->statements = [];
            throw new DataBaseError("Error during Database transaction", 0, $e);
        }
        $this->statements = [];
    }

    /**
     * Destroy the DataBase wrapper. Autocommits on destruction.
     */
    public function __destruct()
    {
        try {
            $this->commit();
        } catch (DataBaseError) {
        }
    }

    /**
     * Obtain underlying PDO handler.
     *
     * @return \PDO
     */
    public function handler(): \PDO
    {
        return $this->dbh;
    }
}
