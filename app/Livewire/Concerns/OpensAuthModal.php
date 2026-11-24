<?php

namespace App\Livewire\Concerns;

use App\Enums\AuthModalMode;
use App\Enums\AuthTrigger;
use App\Queries\Traffic\VisitorVisitQuery;
use App\Support\Games\Play\GameVisitorResolver;
use App\Support\Settings\ProjectSettingsManager;

/**
 * Lets a component ask a guest to authenticate in the authentication modal,
 * over the page the request came from: the same browser event the header
 * links send (docs/dev/auth-modal.md), naming the state to open in and what
 * opened it, for the registration funnel (AuthTrigger).
 */
trait OpensAuthModal
{
    protected function openAuthModal(AuthModalMode $mode = AuthModalMode::Login, AuthTrigger $trigger = AuthTrigger::Other): void
    {
        $this->dispatch('open-auth-modal', mode: $mode->value, trigger: $trigger->value);
    }

    /**
     * A guest's action a member could do — a vote, a rating, a comment vote:
     * sign-in, as always, unless the project offers sign-up instead
     * (`guest_actions_open_sign_up`) to a browser no account has used.
     */
    protected function askGuestToAuthenticate(AuthTrigger $trigger): void
    {
        $browser = app(GameVisitorResolver::class)->browserHash();
        $offersSignUp = app(ProjectSettingsManager::class)->featureEnabled('guest_actions_open_sign_up')
            && ($browser === null || ! app(VisitorVisitQuery::class)->isKnownBrowser($browser));

        $this->openAuthModal($offersSignUp ? AuthModalMode::Register : AuthModalMode::Login, $trigger);
    }
}
