<?php

namespace App\Actions\Auth;

use App\Enums\RegistrationScreeningMode;
use App\Services\Traffic\RequestSignals;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Auth\RegistrationFormStamp;
use App\Support\Observability\SecurityLog;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The checks a sign-up passes after its fields are valid and before an
 * account is created: a field only a script fills in, how long the form took,
 * and how many accounts this network has created lately.
 *
 * What they find goes to the security log, without the name or the address.
 * Whether it is also refused is the mode's to say, so the checks can be
 * watched on real traffic before they turn anyone away. The refusal does not
 * say which check failed: that would tell a script what to change.
 */
final class ScreenRegistrationAction
{
    /** Hidden from people; a script filling in every field fills this too. */
    public const string HONEYPOT_FIELD = 'website';

    /** Where a refusal is shown, on each of the forms. */
    public const string ERROR_FIELD = 'registration';

    public const string EVENT = 'security.registration_screened';

    public const string THROTTLED_EVENT = 'security.registration_screening_throttled';

    private const int LOG_LINES_PER_MINUTE = 30;

    private const int HOUR = 3600;

    private const int DAY = 86400;

    public function __construct(
        private readonly RegistrationFormStamp $stamp,
        private readonly SecurityLog $log,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException when the mode refuses what the checks found
     */
    public function execute(array $validated, ?string $ip): void
    {
        $mode = RegistrationScreeningMode::current();

        if ($mode === RegistrationScreeningMode::Off) {
            return;
        }

        $reasons = [...$this->formReasons($validated), ...$this->limitsReached($ip)];

        // An attempt about to be refused creates no account, so it is not
        // counted; one let through is, and may be what reaches the limit.
        if ($reasons === [] || $mode === RegistrationScreeningMode::Report) {
            $reasons = array_values(array_unique([...$reasons, ...$this->countAccount($ip)]));
        }

        if ($reasons === []) {
            return;
        }

        $refused = $mode === RegistrationScreeningMode::Enforce;

        $this->log->write('registration-screening-log', self::EVENT, self::THROTTLED_EVENT, self::LOG_LINES_PER_MINUTE, [
            'mode' => $mode->value,
            'reasons' => $reasons,
            'refused' => $refused,
            'bot_flags' => app(RequestSignals::class)->read()->botFlags,
        ]);

        if ($refused) {
            throw ValidationException::withMessages([self::ERROR_FIELD => __('auth.register.refused')]);
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

        foreach ($this->windows() as $reason => [$window, $limit]) {
            if (RateLimiter::tooManyAttempts(RateLimitKey::registration($window, $ip), $limit)) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * Counted in one atomic step per window, so of several sign-ups arriving
     * together only the ones within the limit see a count that admits them.
     *
     * @return list<string>
     */
    private function countAccount(?string $ip): array
    {
        $reasons = [];

        foreach ($this->windows() as $reason => [$window, $limit, $seconds]) {
            if (RateLimiter::hit(RateLimitKey::registration($window, $ip), $seconds) > $limit) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
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
