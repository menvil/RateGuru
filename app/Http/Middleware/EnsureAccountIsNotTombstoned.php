<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminal-account guard: a session authenticated as an account that can no
 * longer authenticate (today: a Deleted tombstone) is force-terminated on
 * its next request — logout, session invalidation, CSRF regeneration.
 *
 * This is what makes tombstoning effective across every session backend:
 * AnonymizeUserAccountAction can only revoke database-driver sessions, but a
 * file/redis session from another browser would otherwise stay usable
 * indefinitely. Runs on the whole web group; the admin panel is separately
 * fail-closed via User::canAccessPanel().
 */
final class EnsureAccountIsNotTombstoned
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->canAuthenticate()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        return $next($request);
    }
}
