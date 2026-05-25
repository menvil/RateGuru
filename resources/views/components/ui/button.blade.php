@props([
    'variant' => 'primary',
    'size' => 'md',
    'type' => 'button',
    'disabled' => false,
    'fullWidth' => false,
    'elevated' => false,
])

@php
    $variants = [
        'primary' => 'border-transparent bg-rg-accent text-rg-onAccent hover:bg-rg-accentHover',
        'secondary' => 'border-rg-border2 bg-rg-card text-rg-text2 hover:bg-rg-card2 hover:text-rg-text',
        'ghost' => 'border-transparent bg-transparent text-rg-text2 hover:bg-rg-card2 hover:text-rg-text',
        'danger' => 'border-[rgba(239,68,68,0.45)] bg-[rgba(239,68,68,0.12)] text-[#fca5a5] hover:bg-[rgba(239,68,68,0.18)]',
    ];

    $sizes = [
        'sm' => 'h-8 px-3 text-xs',
        'md' => 'h-[38px] px-4 text-[13px]',
        'lg' => 'h-10 px-5 text-sm',
    ];

    $types = ['button', 'submit'];

    $variantClass = $variants[$variant] ?? $variants['primary'];
    $sizeClass = $sizes[$size] ?? $sizes['md'];
    $type = in_array($type, $types, true) ? $type : 'button';
@endphp

<button
    {{ $attributes
        ->class([
            'inline-flex items-center justify-center gap-2 rounded-rgControl border font-semibold transition-colors',
            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg',
            'disabled:cursor-not-allowed disabled:opacity-45',
            'w-full' => $fullWidth,
            'shadow-rgUpload' => $elevated,
            $variantClass,
            $sizeClass,
        ])
        ->merge(['type' => $type]) }}
    @disabled($disabled)
>
    {{ $slot }}
</button>
