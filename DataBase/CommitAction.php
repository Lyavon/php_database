<?php

namespace Lyavon\DataBase;

enum CommitAction
{
    case Ignore;
    case Insert;
    case Update;
    case Delete;
}
