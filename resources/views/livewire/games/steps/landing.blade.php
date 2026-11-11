{{--
    Landing, as the reference draws it: the game's cover, tilted; how long it
    takes; its title and a few lines; the way in; and whose game it is.
--}}
<section class="rg-game__card" data-testid="game-step-landing">
    @if ($game['cover'])
        <img class="rg-game__cover" src="{{ $game['cover']->src }}" @if ($game['cover']->srcset) srcset="{{ $game['cover']->srcset }}" sizes="{{ $game['cover']->sizes }}" @endif alt="" @if ($game['cover']->width) width="{{ $game['cover']->width }}" height="{{ $game['cover']->height }}" @endif>
    @endif
    <p class="rg-game__eyebrow">{{ __('games.play.pairs_meta', ['count' => $game['roundCount']]) }}</p>
    <h1 class="rg-game__title">{{ $texts['title'] }}</h1>
    @if (($texts['body'] ?? '') !== '')
        <p class="rg-game__lead">{{ $texts['body'] }}</p>
    @endif
    <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="continue" wire:loading.attr="disabled" data-testid="game-start">{{ $texts['button_label'] }} <span aria-hidden="true">→</span></button>
    @if ((bool) ($data['settings']['show_branding'] ?? true))
        <p class="rg-game__note rg-game__note--center">{{ __('games.play.by_site', ['site' => $game['site']]) }}</p>
    @endif
</section>
