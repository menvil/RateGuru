<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional origin a social sign-in link carries when it was clicked in
 * the authentication modal. Lenient on purpose: a missing or unusable value
 * is a standalone-page flow or a safe fallback, and the return path is only
 * followed after AuthReturnUrl has vetted it.
 */
final class SocialRedirectRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            AuthSurfaceContext::SURFACE_FIELD => ['sometimes', 'nullable', 'string'],
            AuthSurfaceContext::MODE_FIELD => ['sometimes', 'nullable', 'string'],
            AuthSurfaceContext::RETURN_FIELD => ['sometimes', 'nullable', 'string'],
        ];
    }
}
