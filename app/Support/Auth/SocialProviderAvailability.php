<?php

namespace App\Support\Auth;

use App\Enums\SocialProvider;
use App\Support\Settings\ProjectSettingsManager;

/**
 * Which social sign-in providers can be used right now.
 *
 * A provider is available when the admin has left it on in Project settings
 * AND its OAuth keys are set in the server's environment: without keys the
 * provider's own consent screen would only show an error, so its buttons are
 * hidden rather than broken. Everything that offers or accepts a provider —
 * the buttons, the Connected accounts card, the redirect and the callback —
 * asks this one class.
 */
final class SocialProviderAvailability
{
    public function __construct(
        private readonly ProjectSettingsManager $settings,
    ) {}

    public function isAvailable(SocialProvider $provider): bool
    {
        return $this->isTurnedOn($provider) && $this->isConfigured($provider);
    }

    public function isTurnedOn(SocialProvider $provider): bool
    {
        return $this->settings->current()->signInProviderTurnedOn($provider);
    }

    public function isConfigured(SocialProvider $provider): bool
    {
        return filled(config("services.{$provider->value}.client_id"))
            && filled(config("services.{$provider->value}.client_secret"));
    }

    /** @return list<SocialProvider> */
    public function available(): array
    {
        return array_values(array_filter(
            SocialProvider::cases(),
            fn (SocialProvider $provider): bool => $this->isAvailable($provider),
        ));
    }

    /** @return list<SocialProvider> */
    public function unavailable(): array
    {
        return array_values(array_filter(
            SocialProvider::cases(),
            fn (SocialProvider $provider): bool => ! $this->isAvailable($provider),
        ));
    }
}
