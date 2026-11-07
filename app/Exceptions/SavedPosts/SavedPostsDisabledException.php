<?php

namespace App\Exceptions\SavedPosts;

use RuntimeException;

class SavedPostsDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Saved posts feature is disabled.');
    }
}
