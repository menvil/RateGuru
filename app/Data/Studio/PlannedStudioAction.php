<?php

namespace App\Data\Studio;

use App\Enums\StudioActionType;
use Carbon\CarbonImmutable;

/**
 * One action a plan's approval writes, as StudioPlanSchedule works it out
 * from what the plan holds — before anything is written, so the Publication
 * step can show the very set the approval would write.
 *
 * `key` names it within its plan — `post`, `node:{id}` for a comment or a
 * reply of the discussion, `vote:{id}` for a prepared vote — and `parentKey`
 * the action it waits for. `actorId` is none only for the post of a plan
 * with no author yet, which no approval writes.
 */
final readonly class PlannedStudioAction
{
    /**
     * @param  array<string, mixed>  $payload  exactly what the action's row keeps
     */
    public function __construct(
        public string $key,
        public ?string $parentKey,
        public StudioActionType $type,
        public ?int $actorId,
        public array $payload,
        public CarbonImmutable $occursAt,
        public CarbonImmutable $runAfter,
    ) {}
}
