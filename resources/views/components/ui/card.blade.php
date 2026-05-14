@props([
    'variant' => 'default',
    'padding' => 'md',
])

@php
    $variants = [
        'default' => 'border-zinc-800 bg-zinc-950/80',
        'elevated' => 'border-zinc-800 bg-zinc-950/80 shadow-xl shadow-black/30',
        'interactive' => 'border-zinc-800 bg-zinc-950/80 transition hover:border-zinc-700 hover:bg-zinc-900/80',
    ];

    $paddings = [
        'none' => 'p-0',
        'sm' => 'p-3',
        'md' => 'p-4',
        'lg' => 'p-6',
    ];

    $variantClass = $variants[$variant] ?? $variants['default'];
    $paddingClass = $paddings[$padding] ?? $paddings['md'];
@endphp

<div {{ $attributes->class([
    'rounded-lg border text-zinc-100',
    $variantClass,
    $paddingClass,
]) }}>
    {{ $slot }}
</div>
