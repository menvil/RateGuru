<?php

namespace App\Support\TranslationEngine\Enums;

/**
 * Whether one item was translated, or one provider call completed.
 */
enum TranslationStatus: string
{
    case Success = 'success';
    case Failed = 'failed';
}
