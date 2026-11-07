<?php

namespace App\Exceptions\Translations;

use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use DomainException;

/**
 * A background AI suggestion that no longer fits its unit, found on the
 * unit's locked row as it was about to be saved: its English changed, or a
 * translation was saved meanwhile. Thrown from the guard of
 * UpdateProjectTranslationAction::handleGuarded(), so nothing is written. It
 * carries what the row stores for the language now, for the page to show.
 */
final class GeneratedSuggestionOutdatedException extends DomainException
{
    public function __construct(
        public readonly ProjectTranslationGenerationIssue $issue,
        public readonly ?string $stored,
    ) {
        parent::__construct($issue->message());
    }
}
