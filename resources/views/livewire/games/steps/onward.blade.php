{{--
    The way on into the site: a prompt, the post it leads to — its picture on
    the left, its title and what the step shows of it on the right: its
    author, upvotes, votes and comments — and the button. A post can open by itself after 3 seconds — once, so going
    back to the game never sends the visitor away again; another site can open
    in a tab of its own, counted without leaving the game.
--}}
@php($onward = $data['onward'])
<div class="rg-game__onward" data-testid="game-onward" data-onward="{{ $onward['kind'] }}"
    @if ($onward['autoOpen']) x-data="{ seconds: 3 }" x-init="const tick = setInterval(() => { seconds--; if (seconds <= 0) { clearInterval(tick); $wire.leave('step') } }, 1000)" @endif
>
    @if (! $onward['title']->isEmpty())
        <x-games.headline :text="$onward['title']" as="h2" size="sm" data-testid="game-onward-title" />
    @endif
    @if (! $onward['text']->isEmpty())
        <p class="rg-game__text" {{ $onward['text']->dir() }}>{{ $onward['text'] }}</p>
    @endif
    @if ($onward['post'] !== null)
        @php($post = $onward['post'])
        <figure class="rg-game__onward-post" data-testid="game-onward-post">
            @if ($post['image'])
                <img class="rg-game__onward-image" src="{{ $post['image']->src }}" @if ($post['image']->srcset) srcset="{{ $post['image']->srcset }}" sizes="{{ $post['image']->sizes }}" @endif @if ($post['image']->width) width="{{ $post['image']->width }}" height="{{ $post['image']->height }}" @endif alt="">
            @else
                <span class="rg-game__onward-image rg-game__onward-image--missing" aria-hidden="true"><x-ui.icon name="image" class="size-8" /></span>
            @endif
            <figcaption class="rg-game__onward-caption">
                <span class="rg-game__onward-title" {{ \App\Support\Locale\WrittenText::dir($post['title']) }}>{{ $post['title'] }}</span>
                @if ($post['author'] !== null || $post['votes'] !== null || $post['likes'] !== null || $post['comments'] !== null)
                    <span class="rg-game__onward-meta" data-testid="game-onward-post-meta">
                        @if ($post['author'] !== null)
                            <span class="rg-game__onward-author"><bdi>{{ '@'.$post['author'] }}</bdi></span>
                        @endif
                        @if ($post['likes'] !== null || $post['votes'] !== null)
                            <span class="rg-game__onward-stats">
                                @if ($post['likes'] !== null)
                                    <span class="rg-game__onward-stat" title="{{ __('ui.voting.up') }}"><x-ui.icon name="arrow-up" class="size-4" /><span class="sr-only">{{ __('ui.voting.up') }}</span>{{ number_format($post['likes']) }}</span>
                                @endif
                                @if ($post['votes'] !== null)
                                    <span class="rg-game__onward-stat">{{ trans_choice('ui.voting.votes_count', $post['votes'], ['count' => number_format($post['votes'])]) }}</span>
                                @endif
                            </span>
                        @endif
                        @if ($post['comments'] !== null)
                            <span class="rg-game__onward-stat"><x-ui.icon name="comment" class="size-4" />{{ trans_choice('ui.comments.count', $post['comments'], ['count' => number_format($post['comments'])]) }}</span>
                        @endif
                    </span>
                @endif
            </figcaption>
        </figure>
    @endif
    @if ($onward['newTab'] && $onward['href'] !== null)
        <a class="rg-game__button rg-game__button--primary rg-game__button--wide" href="{{ $onward['href'] }}" target="_blank" rel="noopener" x-on:click="$wire.leave('step', true)" data-testid="game-onward-go">{{ $onward['button']->inline() }} <span aria-hidden="true">↗</span></a>
        <p class="rg-game__hint">{{ __('games.play.opens_new_tab') }}</p>
    @else
        <button type="button" class="rg-game__button rg-game__button--primary rg-game__button--wide" wire:click="leave('step')" wire:loading.attr="disabled" data-testid="game-onward-go">{{ $onward['button']->inline() }} <span class="rg-mirror-rtl" aria-hidden="true">→</span></button>
        @if ($onward['autoOpen'])
            <p class="rg-game__hint" data-testid="game-onward-auto">{{ __('games.play.opens_by_itself') }}</p>
        @endif
    @endif
</div>
