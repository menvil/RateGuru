<?php

namespace App\Http\Requests\Auth;

use App\Enums\AuthModalMode;
use App\Http\Requests\Auth\Concerns\CarriesAuthSurface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    use CarriesAuthSurface;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'accepted'],
            ...$this->authSurfaceRules(),
        ];
    }

    protected function authMode(): AuthModalMode
    {
        return AuthModalMode::Login;
    }
}
