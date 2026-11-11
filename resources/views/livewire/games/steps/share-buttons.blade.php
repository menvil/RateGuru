{{--
    The share row: the device's own sheet first where there is one, then the
    link to copy and the places to send it, four abreast.
--}}
<div class="rg-game__share-row" x-data="{ copied: false }" data-testid="game-share">
    <button type="button" class="rg-game__button rg-game__button--primary" x-cloak x-show="!! navigator.share" x-on:click="navigator.share({ title: @js($share['text']), text: @js($share['text']), url: @js($share['url']) }).catch(() => {}); $wire.shared('native')">
        <x-ui.icon name="share" class="size-4" />
        {{ __('games.play.share_native') }}
    </button>
    <button type="button" class="rg-game__button rg-game__button--small" x-on:click="navigator.clipboard?.writeText(@js($share['url'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) }); $wire.shared('copy_link')" data-testid="game-share-copy">
        <span x-text="copied ? @js(__('games.play.link_copied')) : @js(__('games.play.copy_link'))">{{ __('games.play.copy_link') }}</span>
    </button>
    @foreach ($share['links'] as $link)
        @if ($link['url'] !== null)
            <a href="{{ $link['url'] }}" class="rg-game__button rg-game__button--small" target="_blank" rel="noopener noreferrer" data-testid="game-share-{{ $link['provider'] }}">{{ $link['label'] }}</a>
        @endif
    @endforeach
</div>
