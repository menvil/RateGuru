<?php

namespace App\Enums;

/**
 * Why a sign-up was refused, as the registration funnel counts it — never a
 * value the form held. A visit keeps every reason it met in one mask
 * (`traffic_visits.signup_refusals`), each reason a bit of its own.
 */
enum SignupRefusal: string
{
    case EmailTaken = 'email_taken';
    case Invalid = 'invalid';
    case Captcha = 'captcha';
    case Screened = 'screened';
    case Throttled = 'throttled';
    case Other = 'other';

    /** The screening's reasons that are a limit reached, or no way to count one. */
    private const array LIMITS = ['hourly_limit', 'daily_limit', 'limit_without_challenge', 'limits_unavailable'];

    public function bit(): int
    {
        return 1 << array_search($this, self::cases(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::EmailTaken => 'Address already taken',
            self::Invalid => 'Something in the form was wrong',
            self::Captcha => 'Failed the challenge',
            self::Screened => 'Stopped as a likely bot',
            self::Throttled => 'Too many sign-ups from the address',
            self::Other => 'Something else',
        };
    }

    /** @param  list<self>  $refusals */
    public static function mask(array $refusals): int
    {
        return array_reduce($refusals, fn (int $mask, self $refusal): int => $mask | $refusal->bit(), 0);
    }

    /** @return list<self> every reason the mask holds, in their order */
    public static function fromMask(int $mask): array
    {
        return array_values(array_filter(self::cases(), fn (self $refusal): bool => ($mask & $refusal->bit()) !== 0));
    }

    /**
     * What the screening found (ScreenRegistrationAction), as the refusals it
     * stands for: a limit reached, the challenge failed, and anything else —
     * the honeypot, the form's stamp, a form filled in too fast, a throwaway
     * or an alias address — a likely bot.
     *
     * @param  list<string>  $reasons
     * @return list<self>
     */
    public static function fromScreening(array $reasons): array
    {
        return self::ordered(array_map(fn (string $reason): self => match (true) {
            in_array($reason, self::LIMITS, true) => self::Throttled,
            $reason === 'challenge_failed' => self::Captcha,
            default => self::Screened,
        }, $reasons));
    }

    /**
     * The rules a sign-up form failed (Validator::failed()): an address
     * taken, apart from any other mistake.
     *
     * @param  array<string, array<string, mixed>>  $failed
     * @return list<self>
     */
    public static function fromFailedRules(array $failed): array
    {
        $refusals = [];

        foreach ($failed as $field => $rules) {
            foreach (array_keys($rules) as $rule) {
                $refusals[] = $field === 'email' && $rule === 'Unique' ? self::EmailTaken : self::Invalid;
            }
        }

        return self::ordered($refusals);
    }

    /**
     * What writing the account refused once the form had passed: an address
     * another sign-up took first, or anything else.
     *
     * @param  array<string, mixed>  $errors
     * @return list<self>
     */
    public static function fromAccountWrite(array $errors): array
    {
        return [array_key_exists('email', $errors) ? self::EmailTaken : self::Other];
    }

    /**
     * @param  list<self>  $refusals
     * @return list<self>
     */
    private static function ordered(array $refusals): array
    {
        return self::fromMask(self::mask($refusals));
    }
}
