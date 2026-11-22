<?php

namespace App\Actions\Auth;

use App\Enums\RegistrationScreeningMode;
use App\Services\Traffic\RequestSignals;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Auth\RegistrationFormStamp;
use App\Support\Observability\SecurityLog;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The checks a sign-up passes after its fields are valid and before an
 * account is created: a field only a script fills in, how long the form took,
 * and how many accounts this network has created lately.
 *
 * What they find goes to the security log, without the name or the address.
 * Whether it is also refused is the mode's to say, so the checks can be
 * watched on real traffic before they turn anyone away. The refusal does not
 * say which check failed: that would tell a script what to change.
 *
 * A cache that cannot be reached is one more thing the checks found. Report
 * mode lets the sign-up through, as it does everything; enforce mode refuses
 * it, since the limits could not be applied. It is not reported from here:
 * the frames of this call hold the submitted form, password included.
 */
final class ScreenRegistrationAction
{
    /** Hidden from people; a script filling in every field fills this too. */
    public const string HONEYPOT_FIELD = 'website';

    /** Where a refusal is shown, on each of the forms. */
    public const string ERROR_FIELD = 'registration';

    public const string EVENT = 'security.registration_screened';

    public const string THROTTLED_EVENT = 'security.registration_screening_throttled';

    private const string LIMITS_UNAVAILABLE = 'limits_unavailable';

    private const int LOG_LINES_PER_MINUTE = 30;

    private const int HOUR = 3600;

    private const int DAY = 86400;

    public function __construct(
        private readonly RegistrationFormStamp $stamp,
        private readonly SecurityLog $log,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, int> the counters this attempt added to, with their
     *                            windows, for release() if no account comes of it
     *
     * @throws ValidationException when the mode refuses what the checks found
     */
    public function execute(array $validated, ?string $ip): array
    {
        $mode = RegistrationScreeningMode::current();

        if ($mode === RegistrationScreeningMode::Off) {
            return [];
        }

        $reasons = [...$this->formReasons($validated), ...$this->limitsReached($ip)];
        $counted = [];

        // An attempt about to be refused creates no account, so it is not
        // counted; one let through is, and may be what reaches the limit.
        if ($reasons === [] || $mode === RegistrationScreeningMode::Report) {
            [$limitReasons, $counted] = $this->countAccount($ip);
            $reasons = array_values(array_unique([...$reasons, ...$limitReasons]));
        }

        if ($reasons === []) {
            return $counted;
        }

        $refused = $mode === RegistrationScreeningMode::Enforce;

        $this->log->write('registration-screening-log', self::EVENT, self::THROTTLED_EVENT, self::LOG_LINES_PER_MINUTE, [
            'mode' => $mode->value,
            'reasons' => $reasons,
            'refused' => $refused,
            'bot_flags' => app(RequestSignals::class)->read()->botFlags,
        ]);

        if ($refused) {
            $this->release($counted);

            throw ValidationException::withMessages([self::ERROR_FIELD => __('auth.register.refused')]);
        }

        return $counted;
    }

    /**
     * Takes back what execute() counted, when no account came of it: only
     * accounts actually created count. Never fails, so the reason the account
     * was not created is the one the person is told.
     *
     * @param  array<string, int>  $counted
     */
    public function release(array $counted): void
    {
        foreach ($counted as $key => $seconds) {
            rescue(fn () => RateLimiter::decrement($key, $seconds), report: false);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function formReasons(array $validated): array
    {
        $reasons = [];

        if (filled($validated[self::HONEYPOT_FIELD] ?? null)) {
            $reasons[] = 'honeypot';
        }

        $age = $this->stamp->ageOf($validated[RegistrationFormStamp::FIELD] ?? null);

        if ($age === null) {
            $reasons[] = 'no_form_stamp';
        } elseif ($age < (int) config('registration.minimum_fill_seconds')) {
            $reasons[] = 'filled_too_fast';
        }

        return $reasons;
    }

    /** @return list<string> */
    private function limitsReached(?string $ip): array
    {
        $reasons = [];

        try {
            foreach ($this->windows() as $reason => [$window, $limit]) {
                if (RateLimiter::tooManyAttempts(RateLimitKey::registration($window, $ip), $limit)) {
                    $reasons[] = $reason;
                }
            }
        } catch (Throwable) {
            return [self::LIMITS_UNAVAILABLE];
        }

        return $reasons;
    }

    /**
     * Counted in one atomic step per window, so of several sign-ups arriving
     * together only the ones within the limit see a count that admits them.
     *
     * @return array{list<string>, array<string, int>} what the count found, and what was counted
     */
    private function countAccount(?string $ip): array
    {
        $reasons = [];
        $counted = [];

        try {
            foreach ($this->windows() as $reason => [$window, $limit, $seconds]) {
                $key = RateLimitKey::registration($window, $ip);
                $hits = RateLimiter::hit($key, $seconds);
                $counted[$key] = $seconds;

                if ($hits > $limit) {
                    $reasons[] = $reason;
                }
            }
        } catch (Throwable) {
            $reasons[] = self::LIMITS_UNAVAILABLE;
        }

        return [$reasons, $counted];
    }

    /** @return array<string, array{string, int, int}> */
    private function windows(): array
    {
        return [
            'hourly_limit' => ['hour', (int) config('rate_limits.registration.per_hour'), self::HOUR],
            'daily_limit' => ['day', (int) config('rate_limits.registration.per_day'), self::DAY],
        ];
    }
}
