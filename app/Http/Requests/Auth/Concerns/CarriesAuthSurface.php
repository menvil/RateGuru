<?php

namespace App\Http\Requests\Auth\Concerns;

use App\Enums\AuthModalMode;
use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lets a login or registration form say which surface it was submitted
 * from. The marker fields are deliberately lenient — a missing or unusable
 * value means "standalone page" or "safe fallback", never a validation
 * error the person could not see or fix — and the return path is only ever
 * followed after AuthReturnUrl has vetted it.
 */
trait CarriesAuthSurface
{
    /** The modal mode this endpoint belongs to. */
    abstract protected function authMode(): AuthModalMode;

    /** @return array<string, list<string>> */
    protected function authSurfaceRules(): array
    {
        return [
            AuthSurfaceContext::SURFACE_FIELD => ['sometimes', 'nullable', 'string'],
            AuthSurfaceContext::MODE_FIELD => ['sometimes', 'nullable', 'string'],
            AuthSurfaceContext::RETURN_FIELD => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * A failed submission from the modal goes back to the page the modal was
     * opened on, with its errors in the modal's own bag.
     */
    protected function failedValidation(Validator $validator): never
    {
        $exception = (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());

        throw AuthSurfaceContext::fromInput($this->all(), $this->authMode())->decorate($exception);
    }
}
