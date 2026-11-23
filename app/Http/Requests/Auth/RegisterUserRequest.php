<?php

namespace App\Http\Requests\Auth;

use App\Actions\Auth\ScreenRegistrationAction;
use App\Enums\AuthModalMode;
use App\Http\Requests\Auth\Concerns\CarriesAuthSurface;
use App\Models\User;
use App\Rules\ContainsNoLink;
use App\Support\Auth\RegistrationFormStamp;
use App\Support\Auth\TurnstileVerifier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

final class RegisterUserRequest extends FormRequest
{
    use CarriesAuthSurface;

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
}
