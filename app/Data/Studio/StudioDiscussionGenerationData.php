<?php

namespace App\Data\Studio;

use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioPostTextTone;

/**
 * Everything an administrator asks a discussion generation for, and nothing
 * more: what to write, in which tone, at the plan's version the editor was
 * drawn from, and —
 *
 * - for a whole discussion: the Personas taking part, chosen by the
 *   administrator, never picked at random, and how many comments on the post
 *   and replies in all;
 * - for a part written again: the comment it is about — a comment, a reply,
 *   or a comment with its replies. Its authors are the ones taking part, so
 *   no Persona is chosen, and none of its comments changes hands.
 *
 * Nothing here is checked; RequestStudioDiscussionGenerationAction does.
 */
final readonly class StudioDiscussionGenerationData
{
    /** @param  array<mixed>  $personaUserIds  the Personas taking part in a whole discussion */
    public function __construct(
        public StudioDiscussionGenerationMode $mode,
        public int $expectedLockVersion,
        public array $personaUserIds = [],
        public ?int $targetNodeId = null,
        public int $rootCount = 0,
        public int $replyCount = 0,
        public StudioPostTextTone $tone = StudioPostTextTone::DEFAULT,
    ) {}

    /**
     * Keyed as the editor's fields and its validation are.
     *
     * @return array{mode: string, expectedLockVersion: int, personaUserIds: array<mixed>, targetNodeId: int|null, rootCount: int, replyCount: int, tone: string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'expectedLockVersion' => $this->expectedLockVersion,
            'personaUserIds' => $this->personaUserIds,
            'targetNodeId' => $this->targetNodeId,
            'rootCount' => $this->rootCount,
            'replyCount' => $this->replyCount,
            'tone' => $this->tone->value,
        ];
    }
}
