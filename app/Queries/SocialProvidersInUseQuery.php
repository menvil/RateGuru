<?php

namespace App\Queries;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;

final class SocialProvidersInUseQuery
{
    /**
     * The given providers that at least one account signs in with.
     *
     * @param  list<SocialProvider>  $providers
     * @return list<SocialProvider>
     */
    public function among(array $providers): array
    {
        if ($providers === []) {
            return [];
        }

        $inUse = SocialAccount::query()
            ->whereIn('provider', array_map(static fn (SocialProvider $provider): string => $provider->value, $providers))
            ->distinct()
            ->pluck('provider')
            ->map(static fn (SocialProvider $provider): string => $provider->value)
            ->all();

        return array_values(array_filter(
            $providers,
            static fn (SocialProvider $provider): bool => in_array($provider->value, $inUse, true),
        ));
    }

    /**
     * How many accounts sign in with each provider, keyed by provider value.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = [];

        foreach (SocialProvider::cases() as $provider) {
            $counts[$provider->value] = SocialAccount::query()->where('provider', $provider->value)->count();
        }

        return $counts;
    }
}
