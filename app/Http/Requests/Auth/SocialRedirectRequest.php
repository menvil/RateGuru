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
    /**
     * Deliberately untyped. A `string` rule would turn an unusable marker —
     * `?_auth_surface[]=modal`, a query string someone copied wrong — into a
     * failed OAuth start, which is the opposite of the fallback this request
     * exists to allow. AuthSurfaceContext::fromInput, AuthModalMode::fromInput
     * and AuthReturnUrl::resolve all take `mixed` and answer "page flow" for
     * anything that is not the value they expect, so the lenient reading is the
     * one that is actually implemented downstream.
     */
    public function rules(): array
    {
        return [
            AuthSurfaceContext::SURFACE_FIELD => ['sometimes', 'nullable'],
            AuthSurfaceContext::MODE_FIELD => ['sometimes', 'nullable'],
            AuthSurfaceContext::RETURN_FIELD => ['sometimes', 'nullable'],
        ];
    }
}
