{{--
    Result Preview: the player's numbers as the headline and the teaser under
    it, or, with partial score off, the teaser in the headline's place, on
    one line (rgGameLine fits it; wire:ignore.self keeps the size it sets
    when the page draws again); then the result itself kept back behind a
    lock until the way on.
--}}
<section class="rg-game__card" data-testid="game-step-result_preview">
    @if ((bool) ($data['settings']['show_partial'] ?? true))
        <h1 class="rg-game__title">{{ $texts['title'] }}</h1>
        @if (($texts['body'] ?? '') !== '')
            <p class="rg-game__lead">{{ $texts['body'] }}</p>
        @endif
    @else
        @php($teaser = ($texts['body'] ?? '') !== '' ? $texts['body'] : $texts['title'])
        <div class="rg-game__line">
            <h1 class="rg-game__title rg-game__title--line" style="--rg-game-line-chars: {{ max(1, mb_strlen($teaser)) }}" x-data="rgGameLine" x-on:resize.window="fit()" wire:ignore.self>{{ $teaser }}</h1>
        </div>
    @endif
    <div class="rg-game__locked" aria-hidden="true">
        <x-ui.icon name="lock" class="size-7" />
        <span class="rg-game__locked-line"></span>
        <span class="rg-game__locked-line rg-game__locked-line--short"></span>
    </div>
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue">{{ $texts['button_label'] }}</button>
</section>
