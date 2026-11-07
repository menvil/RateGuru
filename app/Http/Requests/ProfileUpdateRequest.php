<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'regex:/\A[a-z0-9_]+\z/',
                'max:32',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // A new address takes the account's password: a session left open
            // somewhere must not be able to move the account to another
            // mailbox, and with it every reset link. An account with no
            // password yet — one made through Google or Facebook — has none
            // to give.
            'current_password' => $this->changesEmailOfAccountWithPassword()
                ? ['required', 'string', 'current_password']
                : ['nullable'],
        ];
    }

    private function changesEmailOfAccountWithPassword(): bool
    {
        $user = $this->user();

        return $user->password !== null
            && is_string($this->input('email'))
            && $this->input('email') !== $user->email;
    }
}
