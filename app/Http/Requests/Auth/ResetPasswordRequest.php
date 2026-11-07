<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\NormalizesEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules;

final class ResetPasswordRequest extends FormRequest
{
    use NormalizesEmail;

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:2048'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Rules\Password::defaults()],
        ];
    }
}
