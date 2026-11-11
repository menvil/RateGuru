{{--
    Registration CTA: the invitation, then the sign-up itself — the email or a
    provider first, then a name and a password — as Full Result sets it in,
    with the step's own button and log-in link; and going on without it.
--}}
<section class="rg-game__card" data-testid="game-step-registration">
    <span class="rg-game__tile" aria-hidden="true"><x-ui.icon name="user-plus" class="size-6" /></span>
    <h1 class="rg-game__title rg-game__title--sm">{{ $texts['title'] }}</h1>
    @if (($texts['body'] ?? '') !== '')
        <p class="rg-game__lead">{{ $texts['body'] }}</p>
    @endif
    @if ($data['signup'] !== null)
        @include('livewire.games.steps.signup', [
            'signup' => $data['signup'],
            'preview' => $data['preview'],
            'stepKey' => $data['stepKey'],
            'intro' => false,
            'submitLabel' => $texts['button_label'],
            'loginLabel' => $texts['secondary_label'] ?? '',
        ])
    @endif
    <button type="button" class="rg-game__link" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
</section>
