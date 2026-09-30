<?php

namespace App\Http\Controllers\Profile;

use App\Actions\Auth\UnlinkSocialAccountAction;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\CannotDisconnectSocialAccountException;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\SocialLinkContext;
use App\Support\Auth\SocialProviderGateway;
use App\Support\Profile\ConnectedAccountsResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class ConnectedAccountController extends Controller
{
    /**
     * Start connecting a provider to the signed-in account. The server
     * records the intent — this account, this provider — before the
     * provider round trip, and only a callback that matches it attaches
     * the identity.
     */
    public function store(Request $request, SocialProvider $provider, SocialProviderGateway $gateway): ProviderRedirect
    {
        $user = $request->user();

        assert($user instanceof User);

        try {
            $response = $gateway->redirect($provider);
        } catch (SocialAuthenticationException $exception) {
            return ConnectedAccountsResponse::failure($exception->userMessage());
        }

        SocialLinkContext::start($user, $provider, now())->remember($request->session());

        return $response;
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
