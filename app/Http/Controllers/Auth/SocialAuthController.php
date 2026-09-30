<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompletePendingSocialLinkAction;
use App\Actions\Auth\ResolveSocialLoginAction;
use App\Enums\SocialCallbackIntent;
use App\Enums\SocialLoginOutcome;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCallbackRequest;
use App\Http\Requests\Auth\SocialRedirectRequest;
use App\Models\User;
use App\Support\Auth\AuthSurfaceContext;
use App\Support\Auth\SocialLinkContext;
use App\Support\Auth\SocialProviderGateway;
use App\Support\Profile\ConnectedAccountsResponse;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class SocialAuthController extends Controller
{
    /**
     * Send the person to the provider's consent screen, remembering — in
     * the server-side session only — which surface they left from. This is
     * always a sign-in: a connection abandoned earlier in this session is
     * void from here on. A provider that is switched off never sees the
     * person; they are back on their surface with a message instead.
     */
    public function redirect(
        SocialRedirectRequest $request,
        SocialProvider $provider,
        SocialProviderGateway $gateway,
    ): ProviderRedirect {
        $surface = AuthSurfaceContext::fromInput($request->validated());
        SocialLinkContext::forget($request->session());

        try {
            $response = $gateway->redirect($provider);
        } catch (SocialAuthenticationException $exception) {
            return $surface->redirectAfterSocialFailure($exception->userMessage());
        }

        $surface->remember($request->session());

        return $response;
    }

    /**
     * Finish the round trip. What it may do is decided by what the server
     * recorded when it started — a connection from the Connected accounts
     * card, or a sign-in — never by whether the session happens to be
     * signed in by now.
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
        $session = $request->session();
        $surface = AuthSurfaceContext::pull($session);
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $intent = SocialLinkContext::consume($session, $provider, $user, now());

        if ($intent === SocialCallbackIntent::Connect) {
            assert($user instanceof User);

            return $this->finishConnecting($validated, $provider, $user, $gateway, $resolveSocialLogin, $session);
        }

        if ($intent === SocialCallbackIntent::StaleConnect) {
            // Started as a connection for some account and provider, and this
            // callback is not that: never a sign-in, never a link elsewhere.
            $gateway->abandon($session);
            $message = __('auth.social.link_expired');

            return $user !== null
                ? ConnectedAccountsResponse::failure($message)
                : AuthSurfaceContext::page()->redirectAfterSocialFailure($message);
        }

        if ($user !== null) {
            // A sign-in round trip that finds the session already signed in
            // neither connects the provider to this account nor switches to
            // the account behind the identity. Connecting is started from
            // the card, on purpose.
            $gateway->abandon($session);

            return ConnectedAccountsResponse::failure(
                __('auth.social.already_signed_in', ['provider' => $provider->label()]),
            );
        }

        try {
            $identity = $gateway->identityFromCallback($provider, $validated);
            $result = $resolveSocialLogin->execute($identity, null, $session);
        } catch (SocialAuthenticationException $exception) {
            return $surface->redirectAfterSocialFailure($exception->userMessage());
        }

        if ($result->outcome === SocialLoginOutcome::PendingLink) {
            return $surface->redirectToPendingLink(
                __('auth.social.pending_link', ['provider' => $provider->label()]),
            );
        }

        if ($result->outcome->authenticatedSession()) {
            $session->regenerate();

            // A different provider's identity may be parked in this session
            // because its email belongs to the account that just signed in.
            assert($result->user instanceof User);
            $completePendingSocialLink->execute($result->user, $session);
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

    /**
     * The account that started connecting this provider is back: attach
     * the identity to it. Every outcome lands on the Connected accounts
     * card.
     *
     * @param  array{code?: string|null, error?: string|null}  $validated
     */
    private function finishConnecting(
        array $validated,
        SocialProvider $provider,
        User $user,
        SocialProviderGateway $gateway,
        ResolveSocialLoginAction $resolveSocialLogin,
        Session $session,
    ): RedirectResponse {
        try {
            $identity = $gateway->identityFromCallback($provider, $validated);
            $resolveSocialLogin->execute($identity, $user, $session);
        } catch (SocialAuthenticationException $exception) {
            return ConnectedAccountsResponse::failure($exception->userMessage());
        }

        return ConnectedAccountsResponse::success(
            __('profile.connected.connected', ['provider' => $provider->label()]),
        );
    }
}
