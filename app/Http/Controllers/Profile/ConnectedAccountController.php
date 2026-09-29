<?php

namespace App\Http\Controllers\Profile;

use App\Actions\Auth\UnlinkSocialAccountAction;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\CannotDisconnectSocialAccountException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\SocialProviderGateway;
use App\Support\Profile\ConnectedAccountsResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class ConnectedAccountController extends Controller
{
    /**
     * Start connecting a provider to the signed-in account: the ordinary
     * provider round trip, whose callback sees the signed-in session and
     * attaches the identity instead of signing anyone in.
     */
    public function store(SocialProvider $provider, SocialProviderGateway $gateway): ProviderRedirect
    {
        return $gateway->redirect($provider);
    }

    /**
     * Disconnect a provider from the signed-in account.
     */
    public function destroy(Request $request, SocialProvider $provider, UnlinkSocialAccountAction $unlink): RedirectResponse
    {
        $user = $request->user();

        assert($user instanceof User);

        try {
            $unlink->execute($user, $provider);
        } catch (CannotDisconnectSocialAccountException $exception) {
            return ConnectedAccountsResponse::failure($exception->userMessage());
        }

        return ConnectedAccountsResponse::success(
            __('profile.connected.disconnected', ['provider' => $provider->label()]),
        );
    }
}
