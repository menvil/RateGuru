<?php

namespace App\Actions\Games;

use App\Enums\GameAccountOutcome;
use App\Enums\GameEventType;
use App\Models\Concerns\LocksUsersInOrder;
use App\Models\GameAttempt;
use App\Models\User;
use App\Support\Games\Play\GameVisitor;
use Illuminate\Support\Facades\DB;

/**
 * Gives a person the attempts they played as a guest in this browser session
 * moments ago, when they register or sign in: each attempt the session started
 * within the attribution window, not yet anyone's. A new account is told
 * apart from a sign-in to one that existed. An account that may not sign in —
 * a Persona, a deleted account — gets nothing.
 */
final class ClaimGuestGameAttemptsAction
{
    use LocksUsersInOrder;

    public function __construct(private readonly GameEventRecorder $events) {}

    /**
     * @param  list<string>  $publicIds  the attempts the session started
     * @return int how many attempts became the person's
     */
    public function handle(User $user, array $publicIds, GameAccountOutcome $outcome): int
    {
        if ($publicIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($user, $publicIds, $outcome): int {
            $locked = $this->lockUsersInOrder((int) $user->id)->get($user->id);

            if ($locked === null || ! $locked->canSignIn()) {
                return 0;
            }

            $attempts = GameAttempt::query()
                ->whereIn('public_id', $publicIds)
                ->whereNull('user_id')
                ->where('started_at', '>=', now()->subHours((int) config('games.attribution_hours', 24)))
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                $attempt->forceFill([
                    'user_id' => $locked->id,
                    'account_outcome' => $outcome,
                    'account_outcome_at' => now(),
                ])->save();

                $this->events->record(GameEventType::AccountAttributed, (int) $attempt->game_id, new GameVisitor($attempt->audience, (int) $locked->id, $attempt->visitor_hash), attempt: $attempt, detail: ['provider' => $outcome->value]);
            }

            return $attempts->count();
        });
    }
}
