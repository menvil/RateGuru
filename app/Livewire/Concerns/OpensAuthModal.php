<?php

namespace App\Livewire\Concerns;

use App\Enums\AuthModalMode;
use App\Enums\AuthTrigger;

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
}
