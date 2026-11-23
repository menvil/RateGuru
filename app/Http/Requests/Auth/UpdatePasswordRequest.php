<?php

namespace App\Http\Requests\Auth;

use App\Rules\ContainsNoNullCharacter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class UpdatePasswordRequest extends FormRequest
{
    protected $errorBag = 'updatePassword';

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:255', new ContainsNoNullCharacter, 'current_password'],
            'password' => ['required', 'string', 'max:255', Password::defaults(), 'confirmed'],
        ];
    }
}
