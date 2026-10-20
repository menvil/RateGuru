<?php

namespace App\Data\Studio;

use App\Models\StudioDiscussionNode;

/**
 * One comment of a discussion as an editor — or, later, the AI generator —
 * hands it in to be saved: a comment on the post, or a reply to one.
 *
 * A comment saved before is named by its `id`; a new one has none, and may
 * have a `key` instead, a name that holds within this one save only, so that
 * a new reply can answer a new comment of the same save. A reply names the
 * comment it answers by that comment's `parentId` or `parentKey`, never both.
 * Ids are never taken on trust: one that is not this plan's refuses the save.
 *
 * Nothing here is checked; StudioDiscussionValidationRules is.
 */
final readonly class StudioDiscussionNodeData
{
    public function __construct(
        public ?int $id = null,
        public ?string $key = null,
        public ?int $parentId = null,
        public ?string $parentKey = null,
        public ?int $personaUserId = null,
        public string $body = '',
        public int $sortOrder = 0,
        public int $offsetSeconds = 0,
    ) {}

    /** A comment as it is saved, named by its id. */
    public static function of(StudioDiscussionNode $node): self
    {
        return new self(
            id: $node->id,
            parentId: $node->parent_id,
            personaUserId: $node->persona_user_id,
            body: $node->body,
            sortOrder: $node->sort_order,
            offsetSeconds: $node->offset_seconds,
        );
    }

    /** As it is stored: the text trimmed, an empty key or parent key as none. */
    public function normalized(): self
    {
        return new self(
            id: $this->id,
            key: self::normalizedKey($this->key),
            parentId: $this->parentId,
            parentKey: self::normalizedKey($this->parentKey),
            personaUserId: $this->personaUserId,
            body: trim($this->body),
            sortOrder: $this->sortOrder,
            offsetSeconds: $this->offsetSeconds,
        );
    }

    /** Whether it answers another comment rather than the post. */
    public function isReply(): bool
    {
        return $this->parentId !== null || $this->parentKey !== null;
    }

    /**
     * Keyed as the editor's fields and its validation are.
     *
     * @return array{id: ?int, key: ?string, parentId: ?int, parentKey: ?string, personaUserId: ?int, body: string, sortOrder: int, offsetSeconds: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'parentId' => $this->parentId,
            'parentKey' => $this->parentKey,
            'personaUserId' => $this->personaUserId,
            'body' => $this->body,
            'sortOrder' => $this->sortOrder,
            'offsetSeconds' => $this->offsetSeconds,
        ];
    }

    private static function normalizedKey(?string $key): ?string
    {
        $key = $key !== null ? trim($key) : null;

        return $key === '' ? null : $key;
    }
}
