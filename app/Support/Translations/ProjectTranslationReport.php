<?php

namespace App\Support\Translations;

/**
 * How much of this project's own content — its settings, static pages and
 * content as the database holds them now — a language translates.
 */
final readonly class ProjectTranslationReport
{
    /** @param  list<ProjectTranslationUnit>  $missing  the units that need a translation and have none in this language */
    public function __construct(
        public string $locale,
        public int $required,
        public int $translated,
        public array $missing,
    ) {}

    public function isComplete(): bool
    {
        return $this->missing === [];
    }

    /** Whole percent translated, rounded down; nothing to translate is complete. */
    public function percentage(): int
    {
        return $this->required === 0 ? 100 : intdiv($this->translated * 100, $this->required);
    }

    /**
     * The missing translations grouped by section, in section order.
     *
     * @return array<string, list<ProjectTranslationUnit>>
     */
    public function missingBySection(): array
    {
        $grouped = [];

        foreach (ProjectContentSection::cases() as $section) {
            $items = array_values(array_filter(
                $this->missing,
                fn (ProjectTranslationUnit $unit): bool => $unit->section === $section,
            ));

            if ($items !== []) {
                $grouped[$section->value] = $items;
            }
        }

        return $grouped;
    }
}
