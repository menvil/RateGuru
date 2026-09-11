<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\CompletePendingSocialLinkAction;
use App\Actions\Auth\ResolveSocialLoginAction;
use App\Enums\SocialLoginOutcome;
use App\Enums\SocialProvider;
use App\Exceptions\Auth\SocialAuthenticationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialCallbackRequest;
use App\Models\User;
use App\Support\Auth\SocialProviderGateway;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\RedirectResponse as ProviderRedirect;

class SocialAuthController extends Controller
{
    /**
     * Send the person to the provider's consent screen.
     */
    public function redirect(SocialProvider $provider, SocialProviderGateway $gateway): ProviderRedirect
    {
        return $gateway->redirect($provider);
    }

    /**
     * Finish the round trip: turn the provider's answer into a signed-in
     * account, a pending link, or a generic message back on the login page.
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

        try {
            $identity = $gateway->identityFromCallback($provider, $validated);
            $result = $resolveSocialLogin->execute($identity, $request->user(), $request->session());
        } catch (SocialAuthenticationException $exception) {
            return redirect()->route('login')->withErrors(['social' => $exception->userMessage()]);
        }

        if ($result->outcome === SocialLoginOutcome::PendingLink) {
            return redirect()->route('login')->with(
                'status',
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

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
