@props([
    'palette' => 'carbonara',
    'label' => 'DISH PREVIEW',
    'ratio' => 'feed',
])

@php
    $ratioClass = [
        'feed' => 'aspect-[16/10]',
        'detail' => 'aspect-[4/3]',
        'square' => 'aspect-square',
        'portrait' => 'aspect-[3/4]',
        'video' => 'aspect-video',
    ][$ratio] ?? 'aspect-[16/10]';

    $palettes = [
        'carbonara' => [
            'from' => 'var(--rg-food-carbonara-1)',
            'mid' => 'var(--rg-food-carbonara-2)',
            'to' => 'var(--rg-food-carbonara-3)',
        ],
        'matcha' => [
            'from' => 'var(--rg-food-matcha-1)',
            'mid' => 'var(--rg-food-matcha-2)',
            'to' => 'var(--rg-food-matcha-3)',
        ],
        'ramen' => [
            'from' => '#2f120d',
            'mid' => '#8a2d18',
            'to' => '#e08b3e',
        ],
        'avocado' => [
            'from' => '#18320f',
            'mid' => '#4d7c0f',
            'to' => '#bef264',
        ],
        'neutral' => [
            'from' => '#15151f',
            'mid' => '#2d2438',
            'to' => '#6b4c7b',
        ],
    ];

    $colors = $palettes[$palette] ?? $palettes['neutral'];
@endphp

<div
    data-ui="dish-placeholder"
    {{ $attributes->class([
        "{$ratioClass} relative w-full shrink-0 overflow-hidden rounded-rgMedia border border-rg-borderSoft bg-rg-card",
    ]) }}
    role="img"
    aria-label="{{ $label }}"
    data-ratio="{{ $ratio }}"
    style="
        background:
            radial-gradient(circle at 28% 26%, {{ $colors['to'] }} 0%, transparent 27%),
            radial-gradient(circle at 64% 42%, {{ $colors['mid'] }} 0%, transparent 34%),
            radial-gradient(circle at 50% 82%, rgba(255,255,255,0.12) 0%, transparent 20%),
            linear-gradient(135deg, {{ $colors['from'] }} 0%, {{ $colors['mid'] }} 48%, #0a0a0f 100%);
    "
>
    <span class="absolute inset-0 bg-[linear-gradient(135deg,rgba(255,255,255,0.08)_0,transparent_18%,rgba(0,0,0,0.16)_48%,transparent_70%)]"></span>
    <span class="absolute inset-0 bg-[radial-gradient(circle_at_center,transparent_42%,rgba(0,0,0,0.48)_100%)]"></span>
    <span class="absolute inset-x-8 top-8 h-px bg-white/10"></span>
    <span class="absolute inset-y-8 left-10 w-px rotate-12 bg-white/10"></span>

    <span class="absolute bottom-3 left-1/2 max-w-[88%] -translate-x-1/2 whitespace-nowrap rounded-rgPill border border-rg-borderSoft bg-black/35 px-3 py-1 font-mono text-[10px] font-semibold uppercase tracking-[0.14em] text-rg-text2 backdrop-blur-md">
        {{ $label }}
    </span>
</div>
