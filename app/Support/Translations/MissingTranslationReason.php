<?php

namespace App\Support\Translations;

/**
 * Why a language counts as lacking a translation of a field.
 *
 * The two static page reasons are deliberately conservative: a stored text may
 * happen to be right for the rewritten English too, and is flagged anyway.
 * Completeness only advises — it never blocks enabling a language — so an
 * administrator glancing at a false positive costs less than visitors reading
 * a translation of text the page no longer shows.
 */
enum MissingTranslationReason: string
{
    /** The language has no text for the field. */
    case Untranslated = 'untranslated';

    /**
     * The project rewrote the English and the language has no text of its
     * own: the repository's translation is of the old English.
     */
    case SourceCustomized = 'source_customized';

    /**
     * The project rewrote the English, but the language's stored text is still
     * the repository's translation of the old English — the copy the Project
     * Settings form stores for every language when it saves.
     */
    case StaleRepositoryDefault = 'stale_repository_default';

    public function label(): string
    {
        return match ($this) {
            self::Untranslated => 'Missing',
            self::SourceCustomized => 'Source customized',
            self::StaleRepositoryDefault => 'Stale repository default',
        };
    }

    /** What an administrator needs to know beyond the label; null when the label says it all. */
    public function explanation(): ?string
    {
        return match ($this) {
            self::Untranslated => null,
            self::SourceCustomized => 'The English text was rewritten and this language has no text of its own; the shipped translation is of the old English.',
            self::StaleRepositoryDefault => 'The English text was rewritten, but the stored translation is still the shipped translation of the old English.',
        };
    }
}
