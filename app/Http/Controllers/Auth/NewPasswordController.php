<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResetPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        return redirect()->route('login')->with('status', __($status));
    }
}
