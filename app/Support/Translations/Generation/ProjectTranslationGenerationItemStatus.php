<?php

namespace App\Support\Translations\Generation;

/**
 * What became of one missing translation in a background batch.
 *
 * Queued and running while its chunk waits and works. Ready holds an AI
 * suggestion for the administrator to review; failed and skipped say why
 * there is none. Saved and discarded are the administrator's decisions about
 * a ready one. Only a ready suggestion can be saved or discarded, and none of
 * these states is project content: a saved one was written through the
 * ordinary save.
 */
enum ProjectTranslationGenerationItemStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Saved = 'saved';
    case Discarded = 'discarded';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
