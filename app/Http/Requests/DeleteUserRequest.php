<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Account deletion is confirmed with something only the account holder
 * types deliberately: the password, or — for an account created through
 * Google or Facebook, which has none — the account's own email address.
 */
final class DeleteUserRequest extends FormRequest
{
    protected $errorBag = 'userDeletion';

    public function rules(): array
    {
        $user = $this->user();

        if ($user instanceof User && ! $user->hasPassword()) {
            return [
                'email' => [
                    'required',
                    'string',
                    'max:255',
                    function (string $attribute, mixed $value, Closure $fail) use ($user): void {
                        if (Str::lower(trim((string) $value)) !== Str::lower((string) $user->email)) {
                            $fail(__('profile.delete.email_mismatch'));
                        }
                    },
                ],
            ];
        }

        return [
            'password' => ['required', 'string', 'max:255', 'current_password'],
        ];
    }
}
