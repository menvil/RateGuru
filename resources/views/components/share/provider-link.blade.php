@props([
    'provider',
    'url',
    'label',
])

<a
    href="{{ $url }}"
    target="_blank"
    rel="noopener noreferrer"
    data-testid="share-{{ $provider }}"
    aria-label="{{ $label }}"
    class="inline-flex h-10 w-full items-center justify-center gap-1.5 rounded-rgControl border border-rg-border bg-rg-card2 px-3 text-xs font-semibold text-rg-text2 transition-colors hover:border-rg-border2 hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
>
    {{ $label }}
</a>
