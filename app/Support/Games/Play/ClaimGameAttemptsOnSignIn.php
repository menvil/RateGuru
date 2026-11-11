<?php

namespace App\Support\Games\Play;

use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Enums\GameAccountOutcome;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Throwable;

/**
 * When somebody registers or signs in, the games they played as a guest in
 * this browser session moments before become theirs. Registered fires before
 * the new account's Login, so a new account is counted as registered and the
 * sign-in that follows finds nothing left to claim.
 *
 * It runs inside the registration and sign-in responses: whatever goes wrong
 * here is reported, never allowed to stop anybody signing in.
 */
final class ClaimGameAttemptsOnSignIn
{
    public function __construct(
        private readonly Request $request,
        private readonly ClaimGuestGameAttemptsAction $claim,
    ) {}

    public function handle(Registered|Login $event): void
    {
        if (($event instanceof Login && $event->guard !== 'web') || ! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        $publicIds = GameSessionAttempts::all($this->request->session());

        if ($publicIds === []) {
            return;
        }

        try {
            $this->claim->handle($event->user, $publicIds, $event instanceof Registered ? GameAccountOutcome::Registered : GameAccountOutcome::SignedIn);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
