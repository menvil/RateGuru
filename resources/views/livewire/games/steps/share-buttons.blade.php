{{--
    The share row: the device's own sheet first where there is one, then the
    link to copy and the places to send it, four abreast.

    The copy counts as a share only once the clipboard took the link. Where it
    cannot — no clipboard, an in-app browser that refuses it — the link opens
    below the row, selected, to copy by hand, the same way the site's own
    copy-link control falls back.
--}}
<div
    class="rg-game__share-row"
    x-data="{
        copied: false,
        failed: false,
        async copy() {
            this.copied = false;
            this.failed = false;

            try {
                if (! navigator.clipboard || ! window.isSecureContext) { throw new Error('no clipboard'); }

                await navigator.clipboard.writeText(this.$refs.url.value);
            } catch (error) {
                this.failed = true;
                this.$nextTick(() => this.$refs.url.select());

                return;
            }

            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
            this.$wire.shared('copy_link');
        }
    }"
    data-testid="game-share"
>
    <button type="button" class="rg-game__button rg-game__button--primary" x-cloak x-show="!! navigator.share" x-on:click="navigator.share({ title: @js($share['text']), text: @js($share['text']), url: @js($share['url']) }).catch(() => {}); $wire.shared('native')">
        <x-ui.icon name="share" class="size-4" />
        {{ __('games.play.share_native') }}
    </button>
    <button type="button" class="rg-game__button rg-game__button--small" x-on:click="copy()" data-testid="game-share-copy">
        <span x-text="copied ? @js(__('games.play.link_copied')) : @js(__('games.play.copy_link'))">{{ __('games.play.copy_link') }}</span>
    </button>
    @foreach ($share['links'] as $link)
        @if ($link['url'] !== null)
            <a href="{{ $link['url'] }}" class="rg-game__button rg-game__button--small" target="_blank" rel="noopener noreferrer" data-testid="game-share-{{ $link['provider'] }}">{{ $link['label'] }}</a>
        @endif
    @endforeach
    <div class="rg-game__share-fallback" x-cloak x-show="failed" data-testid="game-share-copy-fallback">
        <input type="text" class="rg-game__input" readonly value="{{ $share['url'] }}" x-ref="url" x-on:click="$el.select()" aria-label="{{ __('games.play.copy_link') }}" data-testid="game-share-copy-url">
        <p class="rg-game__note" role="status">{{ __('sharing.copy_failed') }}</p>
    </div>
</div>
