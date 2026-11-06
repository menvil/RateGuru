<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConfirmablePasswordController extends Controller
{
    /**
     * Show the confirm password view.
     */
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    /**
     * Confirm the user's password.
     */
    public function store(ConfirmPasswordRequest $request, ConfirmPasswordAction $confirmPassword): RedirectResponse
    {
        /** @var array{password: string} $validated */
        $validated = $request->validated();
        $user = $request->user();

        assert($user instanceof User);

        $confirmPassword->execute($user, $validated['password']);

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
