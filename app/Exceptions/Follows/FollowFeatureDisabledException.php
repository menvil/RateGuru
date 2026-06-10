<?php

namespace App\Exceptions\Follows;

use RuntimeException;

class FollowFeatureDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The follow feature is currently disabled.');
    }
}
