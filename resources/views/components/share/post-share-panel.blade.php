@props([
    'post',
    'url' => null,
])

@php
    $shareUrl = $url ?? canonical_post_url($post);
@endphp

<section
    x-data="{
        copied: false,
        async copyShareUrl() {
            await navigator.clipboard.writeText(this.$refs.shareUrl.value);
            this.copied = true;
            setTimeout(() => this.copied = false, 1600);
        }
    }"
    {{ $attributes->merge(['class' => 'space-y-3']) }}
    data-testid="post-share-panel"
>
    <p class="text-xs text-rg-muted">Copy the public post URL.</p>

    <div class="relative">
        <input
            x-ref="shareUrl"
            type="text"
            readonly
            value="{{ $shareUrl }}"
            class="h-10 w-full rounded-rgControl border border-rg-border bg-rg-card2 py-0 pl-3 pr-12 font-mono text-xs text-rg-text outline-none focus-visible:border-rg-accent focus-visible:ring-2 focus-visible:ring-rg-accent/25"
            data-testid="post-share-url"
            aria-label="{{ __('ui.a11y.share_url') }}"
        >

        <button
            type="button"
            x-on:click="copyShareUrl"
            data-testid="post-share-copy"
            aria-label="{{ __('ui.a11y.copy_link') }}"
            class="absolute right-1 top-1 grid size-8 cursor-pointer place-items-center rounded-rgSm text-rg-muted transition hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        >
            <x-ui.icon name="copy" class="size-4" />
        </button>
    </div>

    <p x-show="copied" x-cloak class="text-xs text-rg-accent2" role="status">Copied</p>
</section>
