<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class DeleteUserRequest extends FormRequest
{
    protected $errorBag = 'userDeletion';

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', 'current_password'],
        ];
    }
}
