{{-- Result Preview: the player's numbers, or a teaser, before the full result. --}}
<section class="rg-game__card" data-testid="game-step-result_preview">
    <p class="rg-game__eyebrow">{{ __('games.play.your_result') }}</p>
    @if ((bool) ($data['settings']['show_partial'] ?? true))
        <h1 class="rg-game__title">{{ $texts['title'] }}</h1>
        @if (($texts['body'] ?? '') !== '')
            <p class="rg-game__lead">{{ $texts['body'] }}</p>
        @endif
    @else
        <h1 class="rg-game__title">{{ $texts['body'] !== '' ? $texts['body'] : $texts['title'] }}</h1>
    @endif
    <div class="rg-game__locked" aria-hidden="true"></div>
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ $texts['button_label'] }}</button>
</section>
