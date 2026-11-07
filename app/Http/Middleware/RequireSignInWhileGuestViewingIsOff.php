<?php

namespace App\Http\Middleware;

use App\Support\Settings\ProjectSettingsManager;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project's content — the feed, a post, its share links, a profile —
 * for signed-in people only while Project settings has guest viewing off.
 * The legal and contact pages stay open: sign-in links to them.
 *
 * Registered as Livewire persistent middleware too, so a component drawn on
 * one of these pages answers nothing to a guest once guest viewing is off,
 * a page drawn before the change included.
 */
class RequireSignInWhileGuestViewingIsOff
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && ! $this->settings->featureEnabled('allow_guest_viewing')) {
            throw new AuthenticationException(redirectTo: route('login'));
        }

        return $next($request);
    }
}
