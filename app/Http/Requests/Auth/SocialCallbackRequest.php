<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The query a provider redirects back with. Socialite reads `code` and
 * `state` on its own; the controller only needs to know whether the provider
 * answered with an error or without a code before asking Socialite anything.
 */
final class SocialCallbackRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'nullable', 'string'],
            'state' => ['sometimes', 'nullable', 'string'],
            'error' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
