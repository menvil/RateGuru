<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

/**
 * Stamps a signed-in web session with the account's session generation.
 * Every way of signing in — password, Google, Facebook, "remember me" —
 * goes through Auth::login and fires Login, so none can skip the stamp.
 */
final class RememberSessionGenerationOnLogin
{
    public function __construct(private readonly Request $request) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $this->request->hasSession()) {
            return;
        }

        SessionGeneration::remember($this->request->session(), $event->user);
    }
}
