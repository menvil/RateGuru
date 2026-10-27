<?php

namespace App\Data\Studio;

use App\Enums\StudioActionType;
use App\Models\StudioPlanVote;

/**
 * One prepared vote as an editor hands it in to be saved: which Persona,
 * which kind of vote — `vote_post`, `vote_comment` or `vote_rating` — on
 * what, which way, and when.
 *
 * - on the post: `voteType` up or down;
 * - on a comment: `voteType` up or down, and the prepared comment's
 *   `discussionNodeId` — a comment saved before, so it has an id;
 * - for a rating option: `ratingOptionId`, and no direction. Its rating
 *   question is the option's own; a `ratingGroupId` given with it is only
 *   checked against it.
 *
 * A vote saved before is named by its `id`; a new one has none, and may have
 * a `key` instead, a name that holds within this one save only, which the
 * result names against the id it was given. Ids are never taken on trust:
 * one that is not this plan's refuses the save.
 *
 * The moment and the place are taken as they come — a number of either kind
 * — so that a fraction is refused rather than cut off. Nothing here is
 * checked; StudioVoteValidationRules is.
 */
final readonly class StudioVoteData
{
    public function __construct(
        public ?int $id = null,
        public ?string $key = null,
        public ?string $type = null,
        public ?int $personaUserId = null,
        public ?string $voteType = null,
        public ?int $discussionNodeId = null,
        public ?int $ratingGroupId = null,
        public ?int $ratingOptionId = null,
        public int|float $offsetSeconds = 0,
        public int|float $sortOrder = 0,
    ) {}

    /** A vote as it is saved, named by its id. */
    public static function of(StudioPlanVote $vote): self
    {
        return new self(
            id: $vote->id,
            type: $vote->type->value,
            personaUserId: $vote->persona_user_id,
            voteType: $vote->vote_type?->value,
            discussionNodeId: $vote->discussion_node_id,
            ratingGroupId: $vote->rating_group_id,
            ratingOptionId: $vote->rating_option_id,
            offsetSeconds: $vote->offset_seconds,
            sortOrder: $vote->sort_order,
        );
    }

    /** As it is stored: an empty key as none. */
    public function normalized(): self
    {
        $key = $this->key !== null ? trim($this->key) : null;

        return new self(
            id: $this->id,
            key: $key === '' ? null : $key,
            type: $this->type,
            personaUserId: $this->personaUserId,
            voteType: $this->voteType,
            discussionNodeId: $this->discussionNodeId,
            ratingGroupId: $this->ratingGroupId,
            ratingOptionId: $this->ratingOptionId,
            offsetSeconds: $this->offsetSeconds,
            sortOrder: $this->sortOrder,
        );
    }

    /** The kind of vote, when it is one of the three. */
    public function actionType(): ?StudioActionType
    {
        $type = $this->type !== null ? StudioActionType::tryFrom($this->type) : null;

        return $type?->isVote() ? $type : null;
    }

    /**
     * Keyed as the editor's fields and its validation are.
     *
     * @return array{id: ?int, key: ?string, type: ?string, personaUserId: ?int, voteType: ?string, discussionNodeId: ?int, ratingGroupId: ?int, ratingOptionId: ?int, offsetSeconds: int|float, sortOrder: int|float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'type' => $this->type,
            'personaUserId' => $this->personaUserId,
            'voteType' => $this->voteType,
            'discussionNodeId' => $this->discussionNodeId,
            'ratingGroupId' => $this->ratingGroupId,
            'ratingOptionId' => $this->ratingOptionId,
            'offsetSeconds' => $this->offsetSeconds,
            'sortOrder' => $this->sortOrder,
        ];
    }
}
