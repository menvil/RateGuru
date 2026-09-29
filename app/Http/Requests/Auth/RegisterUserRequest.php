<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthModalMode;
use App\Http\Requests\Auth\Concerns\CarriesAuthSurface;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

final class RegisterUserRequest extends FormRequest
{
    use CarriesAuthSurface;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', 'max:255', 'confirmed', Rules\Password::defaults()],
            ...$this->authSurfaceRules(),
        ];
    }

    protected function authMode(): AuthModalMode
    {
        return AuthModalMode::Register;
    }
}
