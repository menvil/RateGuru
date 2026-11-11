<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Exceptions\Games\CannotPlayGameException;
use App\Models\GameAttempt;
use App\Queries\Games\GameFlowVisitorQuery;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\DB;

/**
 * Moves a completed attempt on from the step it stands on to the next step the
 * visitor sees. A move from any other step — an older tab — moves nothing: the
 * attempt stays where it is. A required email step lets nobody who still sees
 * it past without the email; one the visitor no longer sees — they signed in
 * on it — is passed over like any other.
 */
final class MoveGameAttemptAction
{
    public function __construct(
        private readonly GameFlowVisitorQuery $flowVisitors,
        private readonly GameEventRecorder $events,
    ) {}

    /**
     * @return string|null the step the attempt stands on now; null once the flow is over
     *
     * @throws CannotPlayGameException
     */
    public function handle(GameAttempt $attempt, GameVisitor $visitor, string $fromKey): ?string
    {
        return DB::transaction(function () use ($attempt, $visitor, $fromKey): ?string {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isCompleted() || $locked->current_step_key !== $fromKey) {
                $attempt->setRawAttributes($locked->getAttributes(), true);

                return $locked->current_step_key;
            }

            $snapshot = $locked->version->read();
            $steps = $snapshot->flowSteps();
            $current = GameFlowRunner::find($steps, $fromKey);
            $flowVisitor = $this->flowVisitors->for($visitor, $snapshot, $visitor->audience);

            if ($current?->type === GameFlowStepType::EmailCapture && (bool) $current->setting('required')
                && GameFlowRunner::isVisible($current, $flowVisitor) && ! $locked->emailCapture()->exists()) {
                throw CannotPlayGameException::becauseEmailIsRequired();
            }

            $next = GameFlowRunner::after($steps, $flowVisitor, $fromKey);
            $locked->forceFill(['current_step_key' => $next?->key, 'last_activity_at' => now()])->save();

            if ($next !== null) {
                $this->events->record($next->type === GameFlowStepType::FullResult ? GameEventType::ResultViewed : GameEventType::StepViewed, (int) $locked->game_id, $visitor, attempt: $locked, detail: ['step_type' => $next->type->value]);
            }

            $attempt->setRawAttributes($locked->getAttributes(), true);

            return $next?->key;
        });
    }
}
