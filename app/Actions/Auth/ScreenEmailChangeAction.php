<?php

namespace App\Actions\Auth;

use App\Enums\RegistrationScreeningMode;
use App\Models\User;
use App\Support\Auth\EmailQualityCheck;
use App\Support\Observability\SecurityLog;
use Illuminate\Validation\ValidationException;

/**
 * The same check on an address as a sign-up gets, for someone moving their
 * account to a new one: otherwise an account could register with a clean
 * address and confirm a throwaway one afterwards.
 */
final class ScreenEmailChangeAction
{
    public const string EVENT = 'security.email_change_screened';

    public const string THROTTLED_EVENT = 'security.email_change_screening_throttled';

    private const int LOG_LINES_PER_MINUTE = 30;

    public function __construct(
        private readonly EmailQualityCheck $emails,
        private readonly SecurityLog $log,
    ) {}

    /** @throws ValidationException when the mode refuses the new address */
    public function execute(User $user, string $email): void
    {
        $mode = RegistrationScreeningMode::current();

        if ($mode === RegistrationScreeningMode::Off || $email === $user->email) {
            return;
        }

        $reasons = $this->emails->reasonsFor($email, (int) $user->getKey());

        if ($reasons === []) {
            return;
        }

        $refused = $mode === RegistrationScreeningMode::Enforce;

        $this->log->write('email-change-screening-log', self::EVENT, self::THROTTLED_EVENT, self::LOG_LINES_PER_MINUTE, [
            'user_id' => $user->getKey(),
            'mode' => $mode->value,
            'reasons' => $reasons,
            'refused' => $refused,
        ]);

        if ($refused) {
            throw ValidationException::withMessages($this->emails->refusal($reasons));
        }
    }
}
