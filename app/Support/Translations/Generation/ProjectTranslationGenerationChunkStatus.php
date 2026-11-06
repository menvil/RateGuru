<?php

namespace App\Support\Translations\Generation;

/**
 * One planned provider request of a batch, carried by one queued job.
 *
 * Queued until a job claims it, running while the job works on it, then
 * completed — the request was made, or every item was skipped before it — or
 * failed, when the job could not make it at all. Only a queued chunk can be
 * claimed, so a job delivered twice sends nothing the second time.
 */
enum ProjectTranslationGenerationChunkStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
