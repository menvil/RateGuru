<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\CompletePendingSocialLinkAction;
use App\Actions\Auth\LogoutUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        AuthenticateUserAction $authenticate,
        CompletePendingSocialLinkAction $completePendingSocialLink,
    ): RedirectResponse {
        /** @var array{email: string, password: string, remember?: bool|string} $validated */
        $validated = $request->validated();

        $authenticate->execute($validated, $request);

        $request->session()->regenerate();

        // A social identity parked in this session because its email already
        // belonged to an account is claimed by exactly this proof of ownership.
        $user = $request->user();

        assert($user instanceof User);

        $completePendingSocialLink->execute($user, $request->session());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request, LogoutUserAction $logout): RedirectResponse
    {
        $logout->execute();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
