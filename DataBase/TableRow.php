<?php

namespace Lyavon\DataBase;

use Lyavon\DataBase\CommitAction;
use Lyavon\DataBase\Table;


abstract class TableRow
{
  protected CommitAction $_action = CommitAction::Ignore;
  protected Table $table;

  public function ignore(): void
  {
    $this->_action = CommitAction::Ignore;
  }

  public function insert(): void
  {
    $this->_action = CommitAction::Insert;
  }

  public function update(): void
  {
    $this->_action = CommitAction::Update;
  }

  public function delete(): void
  {
    $this->_action = CommitAction::Delete;
  }

  public function getAction(): CommitAction
  {
    return $this->_action;
  }

  public function __construct($table)
  {
    $this->table = $table;
  }

  public function __destruct()
  {
    if ($this->_action == CommitAction::Ignore)
      return;
    $this->table->dispatch($this);
  }
}
