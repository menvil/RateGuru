@props([
    'provider',
    'url',
    'label',
    'postUrl' => '#',
])

<a
    href="{{ $postUrl }}"
    @click.prevent="window.open({{ \Illuminate\Support\Js::from($url) }}, '_blank', 'noopener,noreferrer')"
    target="_blank"
    rel="noopener noreferrer"
    title="{{ $label }}"
    data-testid="share-{{ $provider }}"
    aria-label="{{ $label }}"
    class="inline-flex size-9 cursor-pointer items-center justify-center rounded-lg border border-rg-border bg-rg-card2 text-rg-text2 transition-colors hover:border-rg-border2 hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
>
    <x-share.social-icon :provider="$provider" />
</a>
