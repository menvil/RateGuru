@props([
    'variant' => 'neutral',
    'size' => 'md',
])

@php
    $variants = [
        'neutral' => 'border-rg-border2 bg-rg-card2 text-rg-text2',
        'accent' => 'border-rg-accentBorder bg-rg-accentSoft text-rg-accent2',
        'success' => 'border-rg-goodBorder bg-rg-goodSoft text-rg-good',
        'warning' => 'border-[rgba(245,158,11,0.55)] bg-[rgba(245,158,11,0.12)] text-[rgb(251,191,36)]',
        'danger' => 'border-[rgba(239,68,68,0.55)] bg-[rgba(239,68,68,0.12)] text-[#fca5a5]',
    ];

    $sizes = [
        'sm' => 'px-2 py-0.5 text-[11px]',
        'md' => 'px-2.5 py-1 text-xs',
    ];

    $variantClass = $variants[$variant] ?? $variants['neutral'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-rgPill border font-semibold leading-none whitespace-nowrap',
    $variantClass,
    $sizeClass,
]) }}>
    {{ $slot }}
</span>
