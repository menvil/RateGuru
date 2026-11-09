{{-- Next Game: another live game to play. --}}
<section class="rg-game__card" data-testid="game-step-next_game">
    <p class="rg-game__eyebrow">{{ ($texts['title'] ?? '') !== '' ? $texts['title'] : '' }}</p>
    @if ($data['nextGame'] !== null)
        <h1 class="rg-game__title">{{ $data['nextGame']['title'] }}</h1>
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="leave('next')" data-testid="game-next-game">{{ $texts['button_label'] }} →</button>
    @endif
    @if ($data['canReplay'])
        <button type="button" class="rg-game__link" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
    @endif
</section>
