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
        class="group flex w-full cursor-pointer flex-col items-center gap-1.5 rounded-rgSm py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
    >
        <span class="flex size-11 items-center justify-center rounded-full border border-rg-border2 bg-rg-accent text-rg-onAccent transition-transform group-hover:scale-105">
            <x-ui.icon name="share" class="size-5" />
        </span>
        <span class="max-w-full truncate text-[11px] font-medium text-rg-text2 transition-colors group-hover:text-rg-text">{{ $buttonLabel }}</span>
    </button>
</div>
