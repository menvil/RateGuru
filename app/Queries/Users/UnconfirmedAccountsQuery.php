<?php

namespace App\Queries\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The members who signed up with an email address and never confirmed one
 * (docs/architecture/user-lifecycle.md, "Unconfirmed accounts"): a week
 * after signing up, the account and everything it did are removed. Signing
 * in with Google or Facebook, staff and Personas are never among them, and
 * neither is an account that confirmed an address once and has since
 * changed it.
 */
final class UnconfirmedAccountsQuery
{
    /** How long an address may stay unconfirmed after signing up. */
    public const int GRACE_DAYS = 7;

    /** Accounts signed up before this moment and still unconfirmed are removed. */
    public static function cutoff(): CarbonInterface
    {
        return now()->subDays(self::GRACE_DAYS);
    }

    /**
     * The accounts to remove, signed up before the cutoff. A pre-filter only:
     * the removal asks again on the locked row.
     *
     * @return Builder<User>
     */
    public function expired(CarbonInterface $signedUpBefore): Builder
    {
        return User::query()
            ->where('role', UserRole::User)
            ->where('status', '!=', UserStatus::Deleted)
            ->whereNull('anonymized_at')
            ->whereNull('email_verified_at')
            ->whereNull('email_first_verified_at')
            ->where('created_at', '<=', $signedUpBefore)
            ->whereNotExists(SocialAccount::query()->whereColumn('social_accounts.user_id', 'users.id')->select('id'));
    }

    /** Whether this account never confirmed an address and is one the cleanup removes. */
    public function isUnconfirmed(User $user): bool
    {
        return $user->role === UserRole::User
            && ! $user->isTombstoned()
            && $user->anonymized_at === null
            && $user->email_verified_at === null
            && $user->email_first_verified_at === null
            && ! SocialAccount::query()->where('user_id', $user->id)->exists();
    }

    /** When the cleanup removes this account, or null for one it never removes. */
    public function removalDate(User $user): ?CarbonInterface
    {
        return $this->isUnconfirmed($user) && $user->created_at !== null
            ? $user->created_at->copy()->addDays(self::GRACE_DAYS)
            : null;
    }
}
