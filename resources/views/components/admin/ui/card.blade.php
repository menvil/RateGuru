@props([
    'title' => null,
    'description' => null,
    'headingLevel' => 2,
    'flush' => false,
    'clip' => false,
])

@php
    $heading = 'h'.max(1, min(6, (int) $headingLevel));
@endphp

<div {{ $attributes->class(['rg-admin-card', 'rg-admin-card--clip' => $clip]) }}>
    @if ($title !== null)
        <div class="rg-admin-card__header">
            <{{ $heading }} class="rg-admin-card__title">{{ $title }}</{{ $heading }}>
            @if ($description !== null)
                <p class="rg-admin-card__description">{{ $description }}</p>
            @endif
        </div>
    @endif

    @if ($flush)
        {{ $slot }}
    @else
        <div class="rg-admin-card__body">{{ $slot }}</div>
    @endif

    @isset($footer)
        <div {{ $footer->attributes->class(['rg-admin-card__footer']) }}>{{ $footer }}</div>
    @endisset
</div>
