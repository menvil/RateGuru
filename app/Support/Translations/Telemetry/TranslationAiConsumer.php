<?php

namespace App\Support\Translations\Telemetry;

/**
 * Which part of RateGuru asked the translation engine for the calls a row
 * records, so usage can be told apart by where it came from.
 *
 * A new consumer of the engine is a new case: the column is a plain string,
 * so it needs no schema change.
 */
enum TranslationAiConsumer: string
{
    /** Translation Center's AI translate, Suggest alternative and Regenerate: one unit at a time. */
    case InteractiveAi = 'interactive_ai';

    /** Translation Center's Generate missing: one queued chunk of a language's missing translations at a time. */
    case GenerateMissing = 'generate_missing';
}
