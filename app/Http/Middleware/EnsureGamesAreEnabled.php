<?php

namespace App\Http\Middleware;

use App\Support\Settings\ProjectSettingsManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Games are reached only while Project settings allows them: otherwise every
 * game address answers that nothing is there. Registered as Livewire
 * persistent middleware too, so a game page drawn before the switch answers
 * nothing more once it is off.
 */
class EnsureGamesAreEnabled
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->settings->featureEnabled('allow_games'), 404);

        return $next($request);
    }
}
