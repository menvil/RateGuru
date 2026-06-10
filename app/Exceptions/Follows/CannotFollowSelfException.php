<?php

namespace App\Exceptions\Follows;

use RuntimeException;

class CannotFollowSelfException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('You cannot follow yourself.');
    }
}
