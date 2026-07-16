<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendPasswordResetLinkRequest;
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

        $status = $sendResetLink->execute($validated['email']);

        return back()->with('status', __($status));
    }
}
