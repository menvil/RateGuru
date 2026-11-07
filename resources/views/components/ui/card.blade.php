@props([
    'variant' => 'default',
    'padding' => null,
])

@php
    $variants = [
        'default' => 'border-rg-border bg-rg-card p-4',
        'panel' => 'border-rg-border bg-rg-card p-5',
        'post' => 'border-rg-border bg-rg-card p-4',
        'selected-post' => 'border-rg-accent bg-rg-card p-4 shadow-rgSelected',
        'detail' => 'border-rg-border bg-rg-card p-5',
        'results' => 'border-rg-border bg-rg-card p-5',
        'comment' => 'border-rg-border bg-rg-card p-5',
        'elevated' => 'border-rg-border bg-rg-card p-4 shadow-rgPopover',
        'interactive' => 'border-rg-border bg-rg-card p-4 transition hover:border-rg-border2 hover:bg-rg-cardHover',
    ];

    $paddings = [
        'none' => 'p-0',
        'sm' => 'p-3',
        'md' => 'p-4',
        'lg' => 'p-6',
    ];

    $variantClass = $variants[$variant] ?? $variants['default'];
    $paddingClass = $padding ? ($paddings[$padding] ?? $paddings['md']) : null;
@endphp

<div {{ $attributes->class([
    'rounded-rgCard border text-rg-text',
    $variantClass,
    $paddingClass,
]) }}>
    {{ $slot }}
</div>
