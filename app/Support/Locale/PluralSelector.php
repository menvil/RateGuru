<?php

namespace App\Support\Locale;

use Illuminate\Translation\MessageSelector;

/**
 * Laravel's plural rules, with the installed languages it has none for
 * answered by the rule they share (LanguageRules::PLURAL_RULES).
 */
final class PluralSelector extends MessageSelector
{
    /**
     * @param  string  $locale
     * @param  float|int  $number
     */
    public function getPluralIndex($locale, $number): int
    {
        return parent::getPluralIndex(LanguageRules::pluralLocale($locale), $number);
    }
}
