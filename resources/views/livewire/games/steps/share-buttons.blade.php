{{-- The share row: copy the link, the device's own sheet where there is one, and the providers. --}}
<div class="rg-game__share-row" x-data="{ copied: false }" data-testid="game-share">
    <button type="button" class="rg-game__button" x-on:click="navigator.clipboard?.writeText(@js($share['url'])).then(() => { copied = true; setTimeout(() => copied = false, 2000) }); $wire.shared('copy_link')" data-testid="game-share-copy">
        <x-ui.icon name="copy" class="size-4" />
        <span x-text="copied ? @js(__('games.play.link_copied')) : @js(__('games.play.copy_link'))">{{ __('games.play.copy_link') }}</span>
    </button>
    <button type="button" class="rg-game__button" x-cloak x-show="!! navigator.share" x-on:click="navigator.share({ title: @js($share['text']), text: @js($share['text']), url: @js($share['url']) }).catch(() => {}); $wire.shared('native')">
        <x-ui.icon name="share" class="size-4" />
        {{ __('games.play.share_native') }}
    </button>
    @foreach ($share['links'] as $link)
        @if ($link['url'] !== null)
            <a href="{{ $link['url'] }}" class="rg-game__button" target="_blank" rel="noopener noreferrer" data-testid="game-share-{{ $link['provider'] }}">{{ $link['label'] }}</a>
        @endif
    @endforeach
</div>
