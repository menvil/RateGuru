<?php

namespace App\Enums;

/**
 * Which checks languages:work asks OpenAI about first: a text written now, then the ones a backfill queued — the
 * feed only cares about fresh posts, and a backfill can queue days of work.
 */
enum ContentLanguageCheckPriority: string
{
    case Live = 'live';
    case Backfill = 'backfill';
}
