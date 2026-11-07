<?php

namespace App\Exceptions\Studio;

use App\Enums\StudioActionFailure;
use RuntimeException;

/**
 * Raised inside an action's run to take back everything the run wrote, so
 * the reason (`failure`) is recorded on the action afterwards, on its own.
 * Never leaves ExecuteStudioAction.
 */
final class StudioActionStoppedException extends RuntimeException
{
    public function __construct(public readonly StudioActionFailure $failure)
    {
        parent::__construct("The studio action stopped: {$failure->value}.");
    }
}
