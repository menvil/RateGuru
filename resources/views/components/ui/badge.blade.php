@props([
    'variant' => 'neutral',
    'size' => 'md',
])

@php
    $variants = [
        'neutral' => 'border-zinc-700/70 bg-zinc-800/70 text-zinc-100',
        'accent' => 'border-sky-400/30 bg-sky-500/15 text-sky-100',
        'success' => 'border-emerald-400/30 bg-emerald-500/15 text-emerald-100',
        'warning' => 'border-amber-400/30 bg-amber-500/15 text-amber-100',
        'danger' => 'border-rose-400/30 bg-rose-500/15 text-rose-100',
    ];

    $sizes = [
        'sm' => 'px-2 py-0.5 text-xs',
        'md' => 'px-2.5 py-1 text-sm',
    ];

    $variantClass = $variants[$variant] ?? $variants['neutral'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
@endphp

<span {{ $attributes->class([
    'inline-flex items-center rounded-full border font-medium leading-none whitespace-nowrap',
    $variantClass,
    $sizeClass,
]) }}>
    {{ $slot }}
</span>
