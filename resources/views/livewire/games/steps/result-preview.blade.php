{{--
    Result Preview: the player's numbers as the headline and the teaser under
    it, or, with partial score off, the teaser in the headline's place, on
    one line — each a game headline (x-games.headline); then the result
    itself kept back behind a lock until the way on.
--}}
<section class="rg-game__card" data-testid="game-step-result_preview">
    @if ((bool) ($data['settings']['show_partial'] ?? true))
        <x-games.headline :text="$shown['title']" />
        @if (($texts['body'] ?? '') !== '')
            <p class="rg-game__lead" {{ $shown['body']->dir() }}>{{ $shown['body'] }}</p>
        @endif
    @else
        @php($teaser = ($texts['body'] ?? '') !== '' ? $shown['body'] : $shown['title'])
        <div class="rg-game__line">
            <x-games.headline :text="$teaser" />
        </div>
    @endif
    <div class="rg-game__locked" aria-hidden="true">
        <x-ui.icon name="lock" class="size-7" />
        <span class="rg-game__locked-line"></span>
        <span class="rg-game__locked-line rg-game__locked-line--short"></span>
    </div>
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ $shown['button_label']->inline() }}</button>
</section>
