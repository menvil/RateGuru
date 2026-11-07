<?php

namespace App\Exceptions\Import;

use RuntimeException;

class UrlImportDisabledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('URL import is disabled by the site configuration.');
    }
}
