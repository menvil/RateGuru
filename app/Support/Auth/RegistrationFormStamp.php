<?php

namespace App\Support\Auth;

/**
 * When a sign-up form was drawn, signed for this session so it can be neither
 * made up nor carried over from another one. Tied to the session's own token
 * rather than the one posted: a request can skip the CSRF check by claiming
 * to be same-origin, and would then choose what the stamp is checked against.
 */
final class RegistrationFormStamp
{
    public const string FIELD = '_registration_started';

    /**
     * A stamp for a form being drawn. A form drawn again after a failed
     * submission keeps the one it had, so fixing a typo quickly is not
     * mistaken for a form filled in too fast.
     */
    public function issue(mixed $previous = null): string
    {
        $startedAt = $this->startedAt($previous) ?? now()->getTimestamp();

        return $startedAt.'.'.$this->signature($startedAt);
    }

    /** How many seconds ago the form was drawn, or null for anything but this session's stamp. */
    public function ageOf(mixed $stamp): ?int
    {
        $startedAt = $this->startedAt($stamp);

        return $startedAt === null ? null : now()->getTimestamp() - $startedAt;
    }

    private function startedAt(mixed $stamp): ?int
    {
        if (! is_string($stamp) || preg_match('/\A(\d{1,12})\.([a-f0-9]{64})\z/', $stamp, $parts) !== 1) {
            return null;
        }

        $startedAt = (int) $parts[1];

        return $startedAt <= now()->getTimestamp() && hash_equals($this->signature($startedAt), $parts[2])
            ? $startedAt
            : null;
    }

    private function signature(int $startedAt): string
    {
        return hash_hmac('sha256', 'registration-form|'.$startedAt.'|'.session()->token(), (string) config('app.key'));
    }
}
