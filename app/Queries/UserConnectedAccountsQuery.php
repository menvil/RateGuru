<?php

namespace App\Queries;

use App\Data\Auth\ConnectedAccounts;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;

final class UserConnectedAccountsQuery
{
    /** @param  list<SocialProvider>  $available  the providers that can be used right now */
    public function forUser(User $user, array $available): ConnectedAccounts
    {
        $accounts = SocialAccount::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (SocialAccount $account): string => $account->provider->value);

        return new ConnectedAccounts($accounts, $user->hasPassword(), $available);
    }
}
