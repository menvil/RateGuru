<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompletePendingSocialLinkAction;
use App\Actions\Auth\ResolveSocialLoginAction;
use App\Enums\SocialLoginOutcome;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCallbackRequest;
use App\Http\Requests\Auth\SocialRedirectRequest;
use App\Models\User;
use App\Support\Auth\AuthSurfaceContext;
use App\Support\Auth\SocialProviderGateway;
use App\Support\Profile\ConnectedAccountsResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class SocialAuthController extends Controller
{
    /**
     * Send the person to the provider's consent screen, remembering — in
     * the server-side session only — which surface they left from.
     */
    public function redirect(
        SocialRedirectRequest $request,
        SocialProvider $provider,
        SocialProviderGateway $gateway,
    ): ProviderRedirect {
        AuthSurfaceContext::fromInput($request->validated())->remember($request->session());

        return $gateway->redirect($provider);
    }

    /**
     * Finish the round trip: turn the provider's answer into a signed-in
     * account, a pending link, a provider connected to the signed-in
     * account, or a generic message on the surface the attempt started from.
     */
    public function callback(
        SocialCallbackRequest $request,
        SocialProvider $provider,
        SocialProviderGateway $gateway,
        ResolveSocialLoginAction $resolveSocialLogin,
        CompletePendingSocialLinkAction $completePendingSocialLink,
    ): RedirectResponse {
        /** @var array{code?: string|null, error?: string|null} $validated */
        $validated = $request->validated();
        $surface = AuthSurfaceContext::pull($request->session());

        // Someone already signed in is connecting a provider to their own
        // account from the profile's Connected accounts card, and every
        // outcome of that lands back on the card, whatever surface an
        // abandoned attempt may have left in the session.
        $connecting = $request->user() !== null;

        try {
            $identity = $gateway->identityFromCallback($provider, $validated);
            $result = $resolveSocialLogin->execute($identity, $request->user(), $request->session());
        } catch (SocialAuthenticationException $exception) {
            return $connecting
                ? ConnectedAccountsResponse::failure($exception->userMessage())
                : $surface->redirectAfterSocialFailure($exception->userMessage());
        }

        if ($result->outcome === SocialLoginOutcome::Linked) {
            return ConnectedAccountsResponse::success(
                __('profile.connected.connected', ['provider' => $provider->label()]),
            );
        }

        if ($result->outcome === SocialLoginOutcome::PendingLink) {
            return $surface->redirectToPendingLink(
                __('auth.social.pending_link', ['provider' => $provider->label()]),
            );
        }

        if ($result->outcome->authenticatedSession()) {
            $request->session()->regenerate();

            // A different provider's identity may be parked in this session
            // because its email belongs to the account that just signed in.
            assert($result->user instanceof User);
            $completePendingSocialLink->execute($result->user, $request->session());
        }

        $response = $surface->redirectAfterLogin(route('dashboard', absolute: false));

        if ($result->passwordRemoved) {
            $response->with('toast', [
                'message' => __('auth.social.password_removed', ['provider' => $provider->label()]),
                'duration' => 12000,
            ]);
        }

        return $response;
    }
}
