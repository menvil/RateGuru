<?php

namespace App\Actions\Games;

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Exceptions\Games\CannotPlayGameException;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Games\Flow\GameFlowRunner;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps the email a guest left on an Email Capture step, with whether they
 * ticked the separate marketing consent and the wording they were shown.
 * Nothing is sent to it, nothing subscribes it, and it is no account. One
 * email per attempt: leaving another replaces it. An attempt an account owns
 * takes none — the step is a guest's — so an email never lands on an attempt
 * claimed meanwhile, whose account may be on its way out.
 */
final class CaptureGameEmailAction
{
    public function __construct(
        private readonly ActionRateLimiter $limiter,
        private readonly GameEventRecorder $events,
    ) {}

    /** @throws CannotPlayGameException */
    public function handle(GameAttempt $attempt, GameVisitor $visitor, string $email, bool $consent): void
    {
        $email = Str::lower(trim($email));

        if (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw CannotPlayGameException::becauseEmailIsInvalid();
        }

        [$most, $per] = config('games.rate_limits.email');
        $this->limiter->hitOrFail(RateLimitKey::gameVisitorAction('game-email', $visitor->identityKey()), (int) $most, (int) $per);

        DB::transaction(function () use ($attempt, $visitor, $email, $consent): void {
            $locked = GameAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $step = $locked->current_step_key !== null ? GameFlowRunner::find($locked->version->read()->flowSteps(), $locked->current_step_key) : null;

            if (! $locked->isCompleted() || $locked->user_id !== null || $step?->type !== GameFlowStepType::EmailCapture) {
                throw CannotPlayGameException::becauseAttemptIsOver();
            }

            $consentShown = (bool) $step->setting('consent_enabled');
            $snapshotStep = $locked->version->read()->step($step->key);

            $capture = GameEmailCapture::query()->where('attempt_id', $locked->id)->first() ?? new GameEmailCapture;
            $capture->forceFill([
                'attempt_id' => $locked->id,
                'game_id' => $locked->game_id,
                'email' => $email,
                'marketing_consent' => $consentShown && $consent,
                'consent_label' => $consentShown ? mb_substr((string) $snapshotStep?->text('consent_label', $locked->locale), 0, 300) : null,
                'captured_at' => now(),
            ])->save();

            $this->events->record(GameEventType::EmailCaptured, (int) $locked->game_id, $visitor, attempt: $locked);
        });
    }
}
