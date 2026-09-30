<?php

namespace App\Data\Auth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Support\Auth\SignInMethods;
use Illuminate\Support\Collection;

/** What the profile's Connected accounts card shows for one account. */
final readonly class ConnectedAccounts
{
    /** @param  Collection<string, SocialAccount>  $accounts  keyed by provider value */
    public function __construct(
        private Collection $accounts,
        private bool $hasPassword,
    ) {}

    public function accountFor(SocialProvider $provider): ?SocialAccount
    {
        return $this->accounts->get($provider->value);
    }

    public function canDisconnect(SocialProvider $provider): bool
    {
        return $this->accountFor($provider) !== null
            && SignInMethods::remainAfterDisconnecting($this->hasPassword, $this->accounts->count() - 1);
    }
}
