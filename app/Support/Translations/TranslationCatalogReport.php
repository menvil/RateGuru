<?php

namespace App\Support\Translations;

/**
 * How much of the application a language translates, and what keeps it from
 * the strict contract CI enforces.
 */
final readonly class TranslationCatalogReport
{
    /** @param  list<TranslationCatalogIssue>  $issues */
    public function __construct(
        public string $locale,
        public int $expectedLines,
        public int $completeLines,
        public array $issues,
    ) {}

    /**
     * True only when the language meets the whole contract. A catalog with an
     * extra key or an orphan file can count every line and still not be.
     */
    public function isComplete(): bool
    {
        return $this->issues === [];
    }

    /** Whole percent of the expected lines that are translated, rounded down. */
    public function percentage(): int
    {
        if ($this->expectedLines === 0) {
            return 100;
        }

        return intdiv($this->completeLines * 100, $this->expectedLines);
    }

    /** @return list<TranslationCatalogIssue> */
    public function issuesOfType(string ...$types): array
    {
        return array_values(array_filter(
            $this->issues,
            fn (TranslationCatalogIssue $issue): bool => in_array($issue->type, $types, true),
        ));
    }
}
