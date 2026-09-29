<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     */
    public function store(ResetPasswordRequest $request, ResetPasswordAction $resetPassword): RedirectResponse
    {
        /** @var array{token: string, email: string, password: string} $validated */
        $validated = $request->validated();

        $status = $resetPassword->execute($validated);

        // Reached from the emailed link while signed in to the same account —
        // how an account created through Google or Facebook sets its first
        // password. A reset of some other address is not news about this
        // profile, so it takes the ordinary route.
        $user = $request->user();

        if ($user instanceof User && Str::lower((string) $user->email) === Str::lower(trim($validated['email']))) {
            return redirect()->route('profile.edit')->with('status', 'password-set');
        }

        return redirect()->route('login')->with('status', __($status));
    }
}
