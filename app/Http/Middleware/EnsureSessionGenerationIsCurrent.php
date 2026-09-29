<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Auth\SessionGeneration;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session that belongs to an older generation of its account — for
 * example the session of whoever registered an email address they never
 * confirmed, once the address's real owner has signed in through Google or
 * Facebook and taken the account over.
 */
final class EnsureSessionGenerationIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $request->hasSession() && ! SessionGeneration::isCurrent($request->session(), $user)) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('status', __('auth.session_ended'));
        }

        return $next($request);
    }
}
