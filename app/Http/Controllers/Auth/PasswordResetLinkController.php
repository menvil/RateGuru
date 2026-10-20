<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Enums\AuthModalMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Handle an incoming password reset link request.
     */
    public function store(
        SendPasswordResetLinkRequest $request,
        SendPasswordResetLinkAction $sendResetLink,
    ): RedirectResponse {
        /** @var array{email: string} $validated */
        $validated = $request->validated();
        $surface = AuthSurfaceContext::fromInput($validated, AuthModalMode::ForgotPassword);

        $status = $surface->guard(fn () => $sendResetLink->execute($validated['email']));

        return $surface->redirectAfterPasswordResetLinkRequest(__($status));
    }
}
