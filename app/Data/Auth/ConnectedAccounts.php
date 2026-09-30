<?php

namespace App\Data\Auth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Support\Auth\SignInMethods;
use Illuminate\Support\Collection;

/** What the profile's Connected accounts card shows for one account. */
final readonly class ConnectedAccounts
{
    /**
     * @param  Collection<string, SocialAccount>  $accounts  keyed by provider value
     * @param  list<SocialProvider>  $available  the providers that can be used right now
     */
    public function __construct(
        private Collection $accounts,
        private bool $hasPassword,
        private array $available,
    ) {}

    /**
     * The providers the card lists: every available one, plus any connected
     * one that has been switched off since, so the person still sees it.
     *
     * @return list<SocialProvider>
     */
    public function providers(): array
    {
        return array_values(array_filter(
            SocialProvider::cases(),
            fn (SocialProvider $provider): bool => $this->isAvailable($provider) || $this->accountFor($provider) !== null,
        ));
    }

    public function accountFor(SocialProvider $provider): ?SocialAccount
    {
        return $this->accounts->get($provider->value);
    }

    public function isAvailable(SocialProvider $provider): bool
    {
        return in_array($provider, $this->available, true);
    }

    public function canConnect(SocialProvider $provider): bool
    {
        return $this->accountFor($provider) === null && $this->isAvailable($provider);
    }

    public function canDisconnect(SocialProvider $provider): bool
    {
        if ($this->accountFor($provider) === null) {
            return false;
        }

        $otherAvailable = $this->accounts
            ->keys()
            ->reject(fn (string $value): bool => $value === $provider->value)
            ->filter(fn (string $value): bool => $this->isAvailable(SocialProvider::from($value)))
            ->count();

        return SignInMethods::remainAfterDisconnecting($this->hasPassword, $otherAvailable);
    }
}
