{{-- Next Game: another live game to play. --}}
<section class="rg-game__card" data-testid="game-step-next_game">
    @if (($texts['title'] ?? '') !== '')
        <p class="rg-game__eyebrow">{{ $texts['title'] }}</p>
    @endif
    @if ($data['nextGame'] !== null)
        <h1 class="rg-game__title rg-game__title--sm">{{ $data['nextGame']['title'] }}</h1>
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="leave('next')" data-testid="game-next-game">{{ $texts['button_label'] }} <span aria-hidden="true">→</span></button>
    @endif
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
