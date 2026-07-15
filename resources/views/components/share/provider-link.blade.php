@props([
    'provider',
    'url',
    'label',
    'postUrl' => '#',
])

@php
    // Official brand colors so each target is recognisable at a glance
    // (share-sheet style: colored round chip + caption below).
    $brandColors = [
        'facebook' => '#1877F2',
        'x' => '#000000',
        'telegram' => '#26A5E4',
        'whatsapp' => '#25D366',
        'reddit' => '#FF4500',
        'pinterest' => '#E60023',
        'email' => '#64748B',
    ];

    $chipColor = $brandColors[$provider] ?? '#64748B';
@endphp

<a
    href="{{ $postUrl }}"
    @click.prevent="window.open({{ \Illuminate\Support\Js::from($url) }}, '_blank', 'noopener,noreferrer')"
    target="_blank"
    rel="noopener noreferrer"
    title="{{ $label }}"
    data-testid="share-{{ $provider }}"
    aria-label="{{ $label }}"
    class="group flex w-full cursor-pointer flex-col items-center gap-1.5 rounded-rgSm py-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
>
    <span
        class="flex size-11 items-center justify-center rounded-full border border-rg-border2 text-white transition-transform group-hover:scale-105"
        style="background-color: {{ $chipColor }}"
    >
        <x-share.social-icon :provider="$provider" class="size-5" />
    </span>
    <span class="max-w-full truncate text-[11px] font-medium text-rg-text2 transition-colors group-hover:text-rg-text">{{ $label }}</span>
</a>
