<?php

namespace App\Enums;

/**
 * Where a discussion generation stands. Queued until a worker claims it,
 * Running while that worker waits for OpenAI, then Completed — a proposal
 * kept — or Failed. Completed says only that a proposal was written: whether
 * an administrator took it into the plan is `applied_at`, apart.
 */
enum StudioDiscussionGenerationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Whether the generation has yet to finish, one way or the other. */
    public function isInProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
