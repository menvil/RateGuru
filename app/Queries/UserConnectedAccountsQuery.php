<?php

namespace App\Queries;

use App\Data\Auth\ConnectedAccounts;
use App\Models\SocialAccount;
use App\Models\User;

final class UserConnectedAccountsQuery
{
    public function forUser(User $user): ConnectedAccounts
    {
        $accounts = SocialAccount::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->keyBy(fn (SocialAccount $account): string => $account->provider->value);

        return new ConnectedAccounts($accounts, $user->hasPassword());
    }
}
