<?php

namespace App\Data\Studio;

use App\Enums\StudioPlanPublicationMode;
use App\Models\StudioPlan;
use Carbon\CarbonImmutable;

/**
 * Everything an editor may set on a Draft, and nothing more: the Persona it
 * is published as, the post's texts and taxonomy, and when it is published.
 * There is no room here for a status, a creator, a version, a post or an
 * approval — the server's to set — nor for the photo, which only
 * SetStudioPlanImageAction and RemoveStudioPlanImageAction change.
 *
 * Every field may be empty: a Draft is saved as incomplete as it is.
 */
final readonly class StudioPlanData
{
    /**
     * @param  array<mixed>  $tagIds  tag ids; anything else is refused by StudioPlanValidationRules
     * @param  array<mixed>  $authorAnswerOptionIds  rating option ids the author answers with, one per rating group at most
     */
    public function __construct(
        public ?int $authorUserId = null,
        public ?string $title = null,
        public ?string $description = null,
        public ?string $sourceUrl = null,
        public ?int $categoryId = null,
        public array $tagIds = [],
        public array $authorAnswerOptionIds = [],
        public StudioPlanPublicationMode $publicationMode = StudioPlanPublicationMode::Now,
        public ?CarbonImmutable $publishAt = null,
    ) {}

    /** A plan as it is stored. */
    public static function of(StudioPlan $plan): self
    {
        return new self(
            authorUserId: $plan->author_user_id,
            title: $plan->title,
            description: $plan->description,
            sourceUrl: $plan->source_url,
            categoryId: $plan->category_id,
            tagIds: $plan->tag_ids,
            authorAnswerOptionIds: $plan->author_answer_option_ids,
            publicationMode: $plan->publication_mode,
            publishAt: $plan->publish_at,
        );
    }

    /**
     * As it is stored: the texts trimmed and an empty text as no text at all,
     * each list of ids as whole numbers, once each and in ascending order,
     * and the date in the application's time zone to the second. A plan
     * published at once has no date to keep.
     */
    public function normalized(): self
    {
        return new self(
            authorUserId: $this->authorUserId,
            title: self::normalizedText($this->title),
            description: self::normalizedText($this->description),
            sourceUrl: self::normalizedText($this->sourceUrl),
            categoryId: $this->categoryId,
            tagIds: self::normalizedIds($this->tagIds),
            authorAnswerOptionIds: self::normalizedIds($this->authorAnswerOptionIds),
            publicationMode: $this->publicationMode,
            publishAt: $this->publicationMode === StudioPlanPublicationMode::Now
                ? null
                : $this->publishAt?->setTimezone((string) config('app.timezone'))->startOfSecond(),
        );
    }

    /**
     * Keyed as the editor's fields and its validation are.
     *
     * @return array{authorUserId: ?int, title: ?string, description: ?string, sourceUrl: ?string, categoryId: ?int, tagIds: array<mixed>, authorAnswerOptionIds: array<mixed>, publicationMode: string, publishAt: ?CarbonImmutable}
     */
    public function toArray(): array
    {
        return [
            'authorUserId' => $this->authorUserId,
            'title' => $this->title,
            'description' => $this->description,
            'sourceUrl' => $this->sourceUrl,
            'categoryId' => $this->categoryId,
            'tagIds' => $this->tagIds,
            'authorAnswerOptionIds' => $this->authorAnswerOptionIds,
            'publicationMode' => $this->publicationMode->value,
            'publishAt' => $this->publishAt,
        ];
    }

    /**
     * As `studio_plans` holds it: the columns an editor sets, and only those.
     *
     * @return array{author_user_id: ?int, title: ?string, description: ?string, source_url: ?string, category_id: ?int, tag_ids: array<mixed>, author_answer_option_ids: array<mixed>, publication_mode: StudioPlanPublicationMode, publish_at: ?CarbonImmutable}
     */
    public function attributes(): array
    {
        return [
            'author_user_id' => $this->authorUserId,
            'title' => $this->title,
            'description' => $this->description,
            'source_url' => $this->sourceUrl,
            'category_id' => $this->categoryId,
            'tag_ids' => $this->tagIds,
            'author_answer_option_ids' => $this->authorAnswerOptionIds,
            'publication_mode' => $this->publicationMode,
            'publish_at' => $this->publishAt,
        ];
    }

    /** A text as it is stored: trimmed, and no text at all when nothing is left. */
    public static function normalizedText(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A list of ids as it is stored: whole numbers — a number written as text
     * read as the number — each once, in ascending order. Anything that is
     * not a list of ids is left as it came, for the rules to refuse rather
     * than to be guessed at.
     *
     * @param  array<mixed>  $ids
     * @return array<mixed>
     */
    public static function normalizedIds(array $ids): array
    {
        if (! array_is_list($ids)) {
            return $ids;
        }

        $ids = array_map(
            fn (mixed $id): mixed => is_string($id) && ($number = filter_var($id, FILTER_VALIDATE_INT)) !== false ? $number : $id,
            $ids,
        );

        foreach ($ids as $id) {
            if (! is_int($id)) {
                return $ids;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
