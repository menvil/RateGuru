@props([
    'url',
    'label' => 'Copy link',
    'copiedLabel' => 'Copied',
])

<div
    data-testid="copy-link-button"
    data-copy-url="{{ $url }}"
>
    <button
        type="button"
        class="inline-flex h-[34px] items-center justify-center rounded-rgControl border border-rg-border bg-rg-card2 px-3 text-xs font-semibold text-rg-text transition-colors hover:bg-rg-card"
    >
        {{ $label }}
    </button>

    <input
        type="text"
        readonly
        value="{{ $url }}"
        class="sr-only"
        data-testid="copy-link-url"
    >
</div>
