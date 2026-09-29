<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Enums\AuthModalMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     */
    public function store(
        RegisterUserRequest $request,
        RegisterUserAction $registerUser,
    ): RedirectResponse {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $request->validated();
        $surface = AuthSurfaceContext::fromInput($validated, AuthModalMode::Register);

        $surface->guard(fn () => $registerUser->execute([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]));

        return $surface->redirectAfterRegistration(route('dashboard', absolute: false));
    }
}
