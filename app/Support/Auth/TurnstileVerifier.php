<?php

namespace App\Support\Auth;

use App\Enums\TurnstileOutcome;
use App\Exceptions\Auth\TurnstileMisconfiguredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Asks Cloudflare whether a sign-up's Turnstile token is good. The token is
 * single-use and proves the challenge was solved for this site and this
 * action, so both are compared with what Cloudflare reports.
 */
final class TurnstileVerifier
{
    /** The form field the widget writes its token to. */
    public const string FIELD = 'cf-turnstile-response';

    public const string ACTION = 'register';

    private const string VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** The longest token Cloudflare issues. */
    private const int TOKEN_LIMIT = 2048;

    private const array SECRET_ERRORS = ['missing-input-secret', 'invalid-input-secret'];

    /** Without both keys there is no widget to solve, so nothing is asked of anyone. */
    public function isConfigured(): bool
    {
        return filled(config('services.turnstile.site_key')) && filled(config('services.turnstile.secret_key'));
    }

    public function verify(mixed $token, string $hostname): TurnstileOutcome
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::TOKEN_LIMIT) {
            return TurnstileOutcome::Rejected;
        }

        try {
            // In the body, never the URL: request URLs reach the error reports.
            // Short, because a person is waiting on the form.
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(4)
                ->withoutRedirecting()
                ->post(self::VERIFY_URL, [
                    'secret' => (string) config('services.turnstile.secret_key'),
                    'response' => $token,
                ]);
        } catch (ConnectionException) {
            return TurnstileOutcome::Unavailable;
        }

        $body = $response->json();

        if ($response->serverError() || ! is_array($body)) {
            return TurnstileOutcome::Unavailable;
        }

        if (($body['success'] ?? false) === true) {
            return ($body['action'] ?? self::ACTION) === self::ACTION && $this->isThisSite($body, $hostname)
                ? TurnstileOutcome::Passed
                : TurnstileOutcome::Rejected;
        }

        return $this->failure((array) ($body['error-codes'] ?? []));
    }

    /**
     * Cloudflare's testing keys always report example.com, so they pass
     * anywhere but production, where a testing key must never open the form.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function isThisSite(array $body, string $hostname): bool
    {
        if (($body['metadata']['result_with_testing_key'] ?? false) === true) {
            return ! app()->isProduction();
        }

        return ($body['hostname'] ?? null) === $hostname;
    }

    /** @param  array<array-key, mixed>  $errors */
    private function failure(array $errors): TurnstileOutcome
    {
        if (array_intersect(self::SECRET_ERRORS, $errors) !== []) {
            report(TurnstileMisconfiguredException::secretRefused());

            return TurnstileOutcome::Unavailable;
        }

        return in_array('internal-error', $errors, true)
            ? TurnstileOutcome::Unavailable
            : TurnstileOutcome::Rejected;
    }
}
