<?php

namespace App\Support\Translations;

/**
 * What one safe backfill run did, one count per field and language it looked
 * at.
 */
final readonly class TranslationBackfillReport
{
    /**
     * @param  int  $filled  missing translations it wrote
     * @param  int  $alreadyPresent  translations that were there already, and stayed as they were
     * @param  int  $skippedCustomized  missing, but the project changed the text the repository translated
     * @param  int  $skippedUnknown  missing, but the content is not in the database or the repository has no text for that language
     */
    public function __construct(
        public int $filled,
        public int $alreadyPresent,
        public int $skippedCustomized,
        public int $skippedUnknown,
    ) {}
}
