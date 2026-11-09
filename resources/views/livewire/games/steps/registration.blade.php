{{-- Registration CTA: the invitation, opening the site's own sign-up; signing in instead is offered too. --}}
<section class="rg-game__card" data-testid="game-step-registration">
    <h1 class="rg-game__title">{{ $texts['title'] }}</h1>
    @if (($texts['body'] ?? '') !== '')
        <p class="rg-game__lead">{{ $texts['body'] }}</p>
    @endif
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="signUp('register')" data-testid="game-registration-register">{{ $texts['button_label'] }}</button>
    @if (($texts['secondary_label'] ?? '') !== '')
        <button type="button" class="rg-game__link" wire:click="signUp('login')">{{ $texts['secondary_label'] }}</button>
    @endif
    <button type="button" class="rg-game__link" wire:click="continue" data-testid="game-continue">{{ __('games.play.continue') }} →</button>
</section>
