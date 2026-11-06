<?php

namespace App\Livewire\Concerns;

use App\Enums\AuthModalMode;

/**
 * Lets a component ask a guest to authenticate in the authentication modal,
 * over the page the request came from: the same browser event the header
 * links send (docs/dev/auth-modal.md), naming the state to open in.
 */
trait OpensAuthModal
{
    protected function openAuthModal(AuthModalMode $mode = AuthModalMode::Login): void
    {
        $this->dispatch('open-auth-modal', mode: $mode->value);
    }
}
