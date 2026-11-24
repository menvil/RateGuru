<?php

namespace App\Actions\Games;

use App\Actions\Traffic\MarkTrafficVisitAction;
use App\Actions\Traffic\RecordRegistrationStepAction;
use App\Enums\TrafficEvent;
use App\Models\GameAttempt;
use App\Support\Games\Flow\FlowStepDraft;
use App\Support\Games\Play\GameVisitor;
use Carbon\CarbonImmutable;

/**
 * An anonymous visitor shown a step that asks for an account: the visit the
 * attempt came on saw a sign-up form — the game's own, which opened it and
 * is the registration form (RecordRegistrationStepAction). Recorded where the
 * attempt enters the step, the first time only.
 */
final class GameSignupFormRecorder
{
    public function __construct(
        private readonly MarkTrafficVisitAction $marks,
        private readonly RecordRegistrationStepAction $steps,
    ) {}

    public function shown(GameAttempt $attempt, FlowStepDraft $step, GameVisitor $visitor): void
    {
        if ($attempt->traffic_visit_id !== null && $visitor->isAnonymous() && $step->asksForAccount()) {
            $now = CarbonImmutable::now();
            $this->marks->handle((int) $attempt->traffic_visit_id, TrafficEvent::SignupForm, $now);
            $this->steps->formShownOn((int) $attempt->traffic_visit_id, $now);
        }
    }
}
