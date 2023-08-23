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

use Lyavon\DataBase\CommitAction;
use Lyavon\DataBase\Table;

/**
 * TableRow is a class representing single row of a corresponding table.
 *
 * TODO: describe usage to refactor.
 */
abstract class TableRow
{
    /**
     * @var CommitAction $_action Action to be performed on object deletion
     * (Ignore by default).
     */
    protected CommitAction $_action = CommitAction::Ignore;
    /**
     * @var Table $table Table associated with the TableRow.
     */
    protected Table $table;

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
     * Create TableRow instance.
     *
     * @param Table $table Table to be used with.
     */
    public function __construct(Table $table)
    {
        $this->table = $table;
    }

    /**
     * TableRow peforms database insteraction only on destruction.
     */
    public function __destruct()
    {
        if ($this->_action == CommitAction::Ignore) {
            return;
        }
        $this->table->dispatch($this);
    }
}
