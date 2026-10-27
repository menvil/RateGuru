<?php

namespace App\Support\StudioAi;

use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioPostTextTone;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use App\Support\Studio\StudioDiscussionValidationRules;

/**
 * Everything one discussion generation asks the model, as the application
 * decided it: the mode, the tone, the context it is written from, how many
 * comments and replies a whole discussion is to have, the latest moment a
 * comment may come, and — for a part written again — the comments of that
 * part, in order (StudioDiscussionContext::scopeOf()).
 */
final readonly class StudioDiscussionPrompt
{
    /**
     * What a generation asks, from the context the plan has now: its mode,
     * tone and counts as it was asked for, the part it writes again as the
     * discussion holds it, and the latest moment the plan's date leaves. None
     * when the discussion no longer holds that part.
     */
    public static function for(StudioPlanDiscussionGeneration $generation, StudioDiscussionContext $context, StudioPlan $plan): ?self
    {
        $parameters = $generation->parameters;
        $scope = $generation->mode->isTargeted() ? $context->scope($generation->mode, $generation->targetNodeId()) : [];

        if ($scope === null) {
            return null;
        }

        return new self(
            mode: $generation->mode,
            tone: StudioPostTextTone::tryFrom((string) ($parameters['tone'] ?? '')) ?? StudioPostTextTone::DEFAULT,
            context: $context,
            rootCount: (int) ($parameters['root_count'] ?? 0),
            replyCount: (int) ($parameters['reply_count'] ?? 0),
            latestOffsetSeconds: StudioDiscussionValidationRules::latestOffsetFor($plan->publication_mode, $plan->publish_at),
            scope: $scope,
        );
    }

    /** @param  list<int>  $scope  the comments written again, by id: a comment, a reply, or a comment then its replies */
    public function __construct(
        public StudioDiscussionGenerationMode $mode,
        public StudioPostTextTone $tone,
        public StudioDiscussionContext $context,
        public int $rootCount = 0,
        public int $replyCount = 0,
        public int $latestOffsetSeconds = 0,
        public array $scope = [],
    ) {}
}
