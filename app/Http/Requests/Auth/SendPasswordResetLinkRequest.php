<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthModalMode;
use App\Http\Requests\Auth\Concerns\CarriesAuthSurface;
use Illuminate\Foundation\Http\FormRequest;

final class SendPasswordResetLinkRequest extends FormRequest
{
    use CarriesAuthSurface;

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            ...$this->authSurfaceRules(),
        ];
    }

    protected function authMode(): AuthModalMode
    {
        return AuthModalMode::ForgotPassword;
    }
}
