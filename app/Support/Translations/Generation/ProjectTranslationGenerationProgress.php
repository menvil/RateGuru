<?php

namespace App\Support\Translations\Generation;

use Carbon\CarbonImmutable;

/**
 * One background generation as monitoring shows it: a language, a status,
 * counts and times — never a unit, a source text, a suggestion, a snapshot or
 * a message.
 *
 * $processed counts every item no longer waiting for its chunk: ready,
 * failed, skipped, and those ready ones an administrator has since saved or
 * discarded. $running says the language's running pointer names it, so it is
 * the generation that holds the language now; otherwise it is the viewer's
 * own, kept until it expires so its results can still be reviewed.
 */
final readonly class ProjectTranslationGenerationProgress
{
    public function __construct(
        public string $locale,
        public ProjectTranslationGenerationStatus $status,
        public int $total,
        public int $processed,
        public int $ready,
        public int $failed,
        public int $skipped,
        public int $saved,
        public int $discarded,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $completedAt,
        public bool $running,
        public bool $startedByViewer,
    ) {}

    public function isActive(): bool
    {
        return $this->status !== ProjectTranslationGenerationStatus::Completed;
    }
}
