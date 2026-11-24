<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ScreenRegistrationAction;
use App\Actions\Traffic\RecordRegistrationStepAction;
use App\Enums\AuthModalMode;
use App\Enums\SignupRefusal;
use App\Exceptions\Auth\RegistrationRefusedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Support\Auth\AuthSurfaceContext;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Traffic\Attribution\SignupOrigin;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

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
        ScreenRegistrationAction $screenRegistration,
        RecordRegistrationStepAction $steps,
        GameVisitorResolver $visitors,
    ): RedirectResponse {
        /** @var array{name: string, email: string, password: string} $validated */
        $validated = $request->validated();
        $surface = AuthSurfaceContext::fromInput($validated, AuthModalMode::Register);
        app(SignupOrigin::class)->remember($surface);
        $browser = $visitors->browserHash();
        $now = CarbonImmutable::now();

        // The form sent, and why a sign-up was refused: registration steps of the browser's visit, never in the way.
        self::record(fn () => $steps->submitted($browser, $now));

        $surface->guard(function () use ($request, $validated, $registerUser, $screenRegistration, $steps, $browser, $now): void {
            try {
                $counted = $screenRegistration->execute($validated, $request->ip());
            } catch (RegistrationRefusedException $refused) {
                self::record(fn () => $steps->refused($browser, $refused->refusals, $now));

                throw $refused;
            }

            try {
                $registerUser->execute([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                ]);
            } catch (ValidationException $noAccount) {
                $screenRegistration->release($counted);
                self::record(fn () => $steps->refused($browser, SignupRefusal::fromAccountWrite($noAccount->errors()), $now));

                throw $noAccount;
            }
        });

        return $surface->redirectAfterRegistration(route('dashboard', absolute: false));
    }

    /** A registration step recorded, its failure reported and never in the way of the sign-up. */
    private static function record(Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
