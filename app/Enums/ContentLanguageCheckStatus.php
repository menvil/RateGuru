<?php

namespace App\Enums;

/**
 * Where the check of a text's language stands: waiting for OpenAI, taken by a run of languages:work, answered,
 * given up on after its attempts (the local detector's answer stands), or not sent at all — a text with no words
 * left once names, emoji and words every language shares are taken out.
 */
enum ContentLanguageCheckStatus: string
{
    case Pending = 'pending';
    case Sending = 'sending';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
