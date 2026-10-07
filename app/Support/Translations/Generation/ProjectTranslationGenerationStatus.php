<?php

namespace App\Support\Translations\Generation;

/**
 * Where a background generation batch is: none of its work started, some of
 * it under way, or none of it left to run. Completed says nothing about
 * success — a completed batch may hold ready, failed and skipped items alike.
 */
enum ProjectTranslationGenerationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
}
