{{-- Go to Feed: the way out to the site. --}}
<section class="rg-game__card" data-testid="game-step-feed">
    <h1 class="rg-game__title rg-game__title--sm">{{ __('games.play.end_title') }}</h1>
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="leave('feed')" data-testid="game-feed">{{ $texts['button_label'] }} <span aria-hidden="true">→</span></button>
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
