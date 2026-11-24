<?php

namespace App\Http\Requests\Auth;

use App\Actions\Auth\ScreenRegistrationAction;
use App\Actions\Traffic\RecordRegistrationStepAction;
use App\Enums\AuthModalMode;
use App\Enums\SignupRefusal;
use App\Http\Requests\Auth\Concerns\CarriesAuthSurface;
use App\Models\User;
use App\Rules\ContainsNoLink;
use App\Support\Auth\RegistrationFormStamp;
use App\Support\Auth\TurnstileVerifier;
use App\Support\Games\Play\GameVisitorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;
use Throwable;

final class RegisterUserRequest extends FormRequest
{
    use CarriesAuthSurface {
        failedValidation as private failForSurface;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new ContainsNoLink],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'max:255', 'confirmed', Rules\Password::defaults()],
            // Carried for ScreenRegistrationAction, which decides what they
            // mean: a rule here would refuse even while it only reports.
            ScreenRegistrationAction::HONEYPOT_FIELD => ['sometimes', 'nullable'],
            RegistrationFormStamp::FIELD => ['sometimes', 'nullable'],
            TurnstileVerifier::FIELD => ['sometimes', 'nullable'],
            ...$this->authSurfaceRules(),
        ];
    }

    protected function authMode(): AuthModalMode
    {
        return AuthModalMode::Register;
    }

    /**
     * A form that failed its rules was sent and refused: a registration step
     * of the browser's visit — an address taken apart from any other
     * mistake, never what was typed — recorded before the form goes back.
     */
    protected function failedValidation(Validator $validator): never
    {
        try {
            app(RecordRegistrationStepAction::class)->refused(app(GameVisitorResolver::class)->browserHash(), SignupRefusal::fromFailedRules($validator->failed()), CarbonImmutable::now());
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->failForSurface($validator);
    }
}
