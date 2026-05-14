@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'fullWidth' => false,
])

@php
    $variants = [
        'primary' => 'border-transparent bg-rg-accent text-rg-text hover:bg-rg-accentHover',
        'secondary' => 'border-rg-border bg-rg-surface text-rg-text hover:bg-rg-surface2',
        'ghost' => 'border-transparent bg-transparent text-rg-text hover:bg-rg-surface',
        'danger' => 'border-transparent bg-rg-danger text-rg-text hover:brightness-110',
    ];

    $sizes = [
        'sm' => 'h-9 px-3 text-sm',
        'md' => 'h-10 px-4 text-sm',
        'lg' => 'h-11 px-5 text-base',
    ];

    $types = ['button', 'submit'];

    $variantClass = $variants[$variant] ?? $variants['primary'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
    $type = in_array($type, $types, true) ? $type : 'button';
@endphp

<button
    {{ $attributes
        ->class([
            'inline-flex items-center justify-center gap-2 rounded-rgControl border font-medium transition',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg',
            'disabled:pointer-events-none disabled:opacity-50',
            'w-full' => $fullWidth,
            $variantClass,
            $sizeClass,
        ])
        ->merge(['type' => $type]) }}
    @disabled($disabled)
>
    {{ $slot }}
</button>
