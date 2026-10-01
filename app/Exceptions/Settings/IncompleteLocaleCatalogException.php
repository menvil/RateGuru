<?php

namespace App\Exceptions\Settings;

use RuntimeException;

/**
 * A language would be newly offered while its application catalogs break the
 * contract TranslationParityTest holds them to — a release that should never
 * have passed CI. It stays off rather than reach visitors half translated.
 */
final class IncompleteLocaleCatalogException extends RuntimeException
{
    public function __construct(public readonly string $locale, int $issues)
    {
        parent::__construct("Locale [{$locale}] cannot be offered: its application translations break the catalog contract ({$issues} issue(s)).");
    }
}
