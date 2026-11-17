{{--
    The way on into the site: a prompt, the post it leads to with its picture,
    and the button. A post can open by itself after 3 seconds — once, so going
    back to the game never sends the visitor away again; another site can open
    in a tab of its own, counted without leaving the game.
--}}
@php($onward = $data['onward'])
<div class="rg-game__onward" data-testid="game-onward" data-onward="{{ $onward['kind'] }}"
    @if ($onward['autoOpen']) x-data="{ seconds: 3 }" x-init="const tick = setInterval(() => { seconds--; if (seconds <= 0) { clearInterval(tick); $wire.leave('step') } }, 1000)" @endif
>
    @if ($onward['title'] !== '')
        <h2 class="rg-game__title rg-game__title--sm" data-testid="game-onward-title">{{ $onward['title'] }}</h2>
    @endif
    @if ($onward['text'] !== '')
        <p class="rg-game__text">{{ $onward['text'] }}</p>
    @endif
    @if ($onward['post'] !== null)
        <figure class="rg-game__onward-post" data-testid="game-onward-post">
            @if ($onward['post']['image'])
                <img class="rg-game__onward-image" src="{{ $onward['post']['image']->src }}" @if ($onward['post']['image']->srcset) srcset="{{ $onward['post']['image']->srcset }}" sizes="{{ $onward['post']['image']->sizes }}" @endif alt="">
            @endif
            <figcaption class="rg-game__onward-caption">{{ $onward['post']['title'] }}</figcaption>
        </figure>
    @endif
    @if ($onward['newTab'] && $onward['href'] !== null)
        <a class="rg-game__button rg-game__button--primary rg-game__button--wide" href="{{ $onward['href'] }}" target="_blank" rel="noopener" x-on:click="$wire.leave('step', true)" data-testid="game-onward-go">{{ $onward['button'] }} <span aria-hidden="true">↗</span></a>
        <p class="rg-game__hint">{{ __('games.play.opens_new_tab') }}</p>
    @else
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="leave('step')" wire:loading.attr="disabled" data-testid="game-onward-go">{{ $onward['button'] }} <span aria-hidden="true">→</span></button>
        @if ($onward['autoOpen'])
            <p class="rg-game__hint" data-testid="game-onward-auto">{{ __('games.play.opens_by_itself') }}</p>
        @endif
    @endif
</div>
