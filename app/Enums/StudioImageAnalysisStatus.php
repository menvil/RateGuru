<?php

namespace App\Enums;

/**
 * Where the latest analysis attempt of a plan's photo stands. Queued until a
 * worker claims it, Running while that worker reads the photo and waits for
 * OpenAI, then Completed or Failed. A photo with no attempt has no status at
 * all: it is not analysed yet.
 */
enum StudioImageAnalysisStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Whether the attempt has yet to finish, one way or the other. */
    public function isInProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
