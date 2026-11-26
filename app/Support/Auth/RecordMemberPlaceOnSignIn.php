<?php

namespace App\Support\Auth;

use App\Actions\Users\RecordMemberPlaceAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Throwable;

/**
 * An account made records where it was made from; a sign-in — a password, Google or Facebook, or the remember-me
 * cookie bringing a member back after their session ran out — where the visit it starts came from. Inside the
 * registration and sign-in responses: whatever goes wrong is reported, never allowed to stop anybody signing in.
 */
final class RecordMemberPlaceOnSignIn
{
    public function __construct(
        private readonly RecordMemberPlaceAction $places,
        private readonly Request $request,
    ) {}

    public function handle(Registered|Login $event): void
    {
        if (($event instanceof Login && $event->guard !== 'web') || ! $event->user instanceof User) {
            return;
        }

        try {
            $now = CarbonImmutable::now();

            $event instanceof Registered
                ? $this->places->signedUp($event->user, $this->request->ip(), $now)
                : $this->places->visitStarted($event->user, $this->request->ip(), $now);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
