<?php

namespace App\Support\Auth;

use App\Enums\AuthModalMode;
use Illuminate\Http\Request;

/**
 * Whether the authentication modal starts open on this page load, and in
 * which mode.
 *
 * It opens by itself for exactly two reasons, both explicit: the previous
 * request was a form post that marked itself as coming from the modal and
 * failed validation, or a social round trip that started in the modal ended
 * in an expected failure or a pending link. The mere presence of validation
 * errors on a page never opens it — those may belong to any other form.
 */
final readonly class AuthModalState
{
    private function __construct(
        public bool $open,
        public AuthModalMode $mode,
        public ?string $notice,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $flash = $request->hasSession() ? $request->session()->get(AuthSurfaceContext::FLASH_KEY) : null;

        if (is_array($flash)) {
            $notice = $flash['notice'] ?? null;

            return new self(
                true,
                AuthModalMode::fromInput($flash['mode'] ?? null),
                is_string($notice) && $notice !== '' ? $notice : null,
            );
        }

        if ($request->hasSession() && $request->old(AuthSurfaceContext::SURFACE_FIELD) === AuthSurfaceContext::MODAL) {
            return new self(true, AuthModalMode::fromInput($request->old(AuthSurfaceContext::MODE_FIELD)), null);
        }

        return new self(false, AuthModalMode::Login, null);
    }

    /** Whether this mode's form is the one that was just submitted or addressed. */
    public function isActive(AuthModalMode $mode): bool
    {
        return $this->open && $this->mode === $mode;
    }
}
