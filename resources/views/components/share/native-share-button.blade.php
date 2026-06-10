@props([
    'title',
    'text',
    'url',
    'label' => null,
])

@php
    $buttonLabel = $label ?? __('sharing.native');
@endphp

<div
    x-data="rgNativeShare({ title: @js($title), text: @js($text), url: @js($url) })"
    x-show="supported"
    x-cloak
>
    <button
        type="button"
        x-on:click="share"
        title="{{ $buttonLabel }}"
        data-testid="share-native"
        aria-label="{{ $buttonLabel }}"
        class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border border-rg-border bg-rg-card2 text-rg-text2 transition-colors hover:border-rg-border2 hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
    >
        <x-ui.icon name="share" class="size-4" />
    </button>
</div>
