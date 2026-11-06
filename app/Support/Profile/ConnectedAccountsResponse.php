<?php

namespace App\Support\Profile;

use Illuminate\Http\RedirectResponse;

/**
 * Every connect and disconnect ends on the Connected accounts card of the
 * profile — success or refusal, whichever way the round trip went.
 */
final class ConnectedAccountsResponse
{
    /** Its own bag, so a refusal never shows up under another profile form. */
    public const string ERROR_BAG = 'connectedAccounts';

    public const string STATUS_KEY = 'connected_accounts_status';

    public const string FRAGMENT = 'connected-accounts';

    public static function success(string $message): RedirectResponse
    {
        return self::redirect()->with(self::STATUS_KEY, $message);
    }

    public static function failure(string $message): RedirectResponse
    {
        return self::redirect()->withErrors(['social' => $message], self::ERROR_BAG);
    }

    private static function redirect(): RedirectResponse
    {
        return redirect()->to(route('profile.edit', absolute: false).'#'.self::FRAGMENT);
    }
}
