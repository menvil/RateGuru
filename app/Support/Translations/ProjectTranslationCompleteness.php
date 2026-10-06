<?php

namespace App\Support\Translations;

/**
 * How completely each language translates this project's own content, read
 * from the database as it is now — admin-created categories and tags
 * included, not just what a preset shipped.
 *
 * What is counted is ProjectTranslationCatalog's to say: this class lists no
 * content and knows no storage of its own, so Languages counts exactly what
 * Translation Center edits. A unit whose reference (English) text is blank
 * needs no translation; the reference language itself is complete through its
 * base columns. Only presence is measured — a non-blank string — never
 * quality, and repository config is never consulted for what a project shows.
 */
final class ProjectTranslationCompleteness
{
    public function __construct(private readonly ProjectTranslationCatalog $catalog) {}

    public function report(string $locale): ProjectTranslationReport
    {
        return $this->reports([$locale])[$locale];
    }

    /**
     * Reports for several languages from one read of the content.
     *
     * @param  list<string>  $locales
     * @return array<string, ProjectTranslationReport>
     */
    public function reports(array $locales): array
    {
        return $this->reportsFor($this->catalog->units(), $locales);
    }

    /**
     * Reports for several languages over units already read, for a screen
     * that also shows the units themselves.
     *
     * @param  list<ProjectTranslationUnit>  $units
     * @param  list<string>  $locales
     * @return array<string, ProjectTranslationReport>
     */
    public function reportsFor(array $units, array $locales): array
    {
        $required = array_values(array_filter($units, fn (ProjectTranslationUnit $unit): bool => $unit->requiresTranslation()));
        $reports = [];

        foreach ($locales as $locale) {
            $missing = array_values(array_filter($required, fn (ProjectTranslationUnit $unit): bool => ! $unit->isTranslatedInto($locale)));

            $reports[$locale] = new ProjectTranslationReport($locale, count($required), count($required) - count($missing), $missing);
        }

        return $reports;
    }
}
