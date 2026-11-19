<?php

namespace App\Support\Traffic\Reports;

/**
 * The sign-ups of one row of a traffic report: how many visits signed up,
 * how many of them confirmed the account and how long after signing up, and
 * of the rest those whose window to confirm has closed — removed with
 * everything they did, never counted — against those still inside it. The
 * three time counts are apart, not cumulative; whatever confirmed later than
 * a day is what remains of the confirmed.
 */
final readonly class SignupFigures
{
    public function __construct(
        public int $signedUp = 0,
        public int $confirmed = 0,
        public int $neverConfirmed = 0,
        public int $waiting = 0,
        public int $confirmedIn10Minutes = 0,
        public int $confirmedInAnHour = 0,
        public int $confirmedInADay = 0,
    ) {}

    /** @param  array<string, mixed>|object  $row */
    public static function fromRow(array|object $row): self
    {
        $row = (array) $row;

        return new self(
            (int) ($row['signed_up'] ?? 0),
            (int) ($row['confirmed'] ?? 0),
            (int) ($row['never_confirmed'] ?? 0),
            (int) ($row['waiting'] ?? 0),
            (int) ($row['confirmed_10_minutes'] ?? 0),
            (int) ($row['confirmed_hour'] ?? 0),
            (int) ($row['confirmed_day'] ?? 0),
        );
    }

    public function plus(self $other): self
    {
        return new self(
            $this->signedUp + $other->signedUp,
            $this->confirmed + $other->confirmed,
            $this->neverConfirmed + $other->neverConfirmed,
            $this->waiting + $other->waiting,
            $this->confirmedIn10Minutes + $other->confirmedIn10Minutes,
            $this->confirmedInAnHour + $other->confirmedInAnHour,
            $this->confirmedInADay + $other->confirmedInADay,
        );
    }

    /** What is left of these figures once the other's are taken away: the rows a table does not show. */
    public function minus(self $other): self
    {
        return new self(
            max(0, $this->signedUp - $other->signedUp),
            max(0, $this->confirmed - $other->confirmed),
            max(0, $this->neverConfirmed - $other->neverConfirmed),
            max(0, $this->waiting - $other->waiting),
            max(0, $this->confirmedIn10Minutes - $other->confirmedIn10Minutes),
            max(0, $this->confirmedInAnHour - $other->confirmedInAnHour),
            max(0, $this->confirmedInADay - $other->confirmedInADay),
        );
    }

    /** The confirmed accounts that took longer than a day. */
    public function confirmedLater(): int
    {
        return max(0, $this->confirmed - $this->confirmedIn10Minutes - $this->confirmedInAnHour - $this->confirmedInADay);
    }

    /** Confirmed over signed up; null without a sign-up. */
    public function confirmRate(): ?float
    {
        return $this->signedUp === 0 ? null : $this->confirmed / $this->signedUp;
    }
}
