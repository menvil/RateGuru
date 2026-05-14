@props([
    'score',
    'active' => 'none',
    'orientation' => 'vertical',
])

@php
    $isHorizontal = $orientation === 'horizontal';
    $scoreClass = $active === 'up' ? 'text-rg-good' : ($active === 'down' ? 'text-rg-accent2' : 'text-rg-text2');
@endphp

<div
    data-ui="vote-rail"
    {{ $attributes->class([
        $isHorizontal ? 'flex-row gap-2 px-0' : 'w-8 flex-col gap-1 pt-1.5',
        'flex items-center',
    ]) }}
>
    <button type="button" class="{{ $active === 'up' ? 'text-rg-good' : 'text-rg-muted' }} rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-good">
        <x-ui.icon name="arrow-up" class="size-4" />
    </button>
    <span class="{{ $scoreClass }} text-[13px] font-bold">{{ $score }}</span>
    <button type="button" class="{{ $active === 'down' ? 'text-rg-accent2' : 'text-rg-muted' }} rounded-rgSm p-1 transition hover:bg-rg-card2 hover:text-rg-accent2">
        <x-ui.icon name="arrow-down" class="size-4" />
    </button>
</div>
