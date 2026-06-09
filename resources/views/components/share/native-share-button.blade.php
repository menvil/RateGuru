@props([
    'title',
    'text',
    'url',
    'label' => __('sharing.native'),
])

<div
    x-data="rgNativeShare({ title: @js($title), text: @js($text), url: @js($url) })"
    x-show="supported"
    x-cloak
>
    <button
        type="button"
        x-on:click="share"
        data-testid="share-native"
        class="inline-flex h-[34px] items-center gap-1.5 rounded-rgControl border border-rg-border bg-rg-card2 px-3 text-xs font-semibold text-rg-text2 transition-colors hover:border-rg-border2 hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
        :aria-label="'{{ $label }}'"
    >
        {{ $label }}
    </button>
</div>
