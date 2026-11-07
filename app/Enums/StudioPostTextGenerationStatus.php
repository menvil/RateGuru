<?php

namespace App\Enums;

/**
 * Where a batch of post text suggestions stands. Queued until a worker
 * claims it, Running while that worker waits for OpenAI, then Completed —
 * its titles and descriptions kept — or Failed.
 */
enum StudioPostTextGenerationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Whether the batch has yet to finish, one way or the other. */
    public function isInProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
