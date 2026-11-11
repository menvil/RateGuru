<?php

use App\Enums\GameFlowStepType;
use App\Enums\GameStepAudience;
use App\Models\GameFlow;
use App\Models\GameFlowStep;

/*
 * A step of a type takes that type's audience, settings and texts, and stays
 * in the flow and at the position it was put in, however it was given them.
 */

it('keeps a step of a type in the flow it was made for', function () {
    $flow = GameFlow::factory()->create();

    $step = GameFlowStep::factory()->for($flow, 'flow')->state(['position' => 3])->type(GameFlowStepType::Registration)->create();

    expect($step->flow_id)->toBe($flow->id)
        ->and($step->position)->toBe(3)
        ->and($step->type)->toBe(GameFlowStepType::Registration)
        ->and($step->audience)->toBe(GameStepAudience::Anonymous)
        ->and(GameFlow::query()->count())->toBe(1);
});
