@props([
    'name',
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'disabled' => false,
    'error' => false,
])

@php
    $baseClass = 'block h-10 w-full rounded-rgControl border bg-rg-card2 px-3 text-[13.5px] text-rg-text placeholder:text-rg-muted shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25 disabled:cursor-not-allowed disabled:opacity-45';
    $stateClass = $error
        ? 'border-[rgba(239,68,68,0.65)] focus-visible:border-[rgba(239,68,68,0.85)]'
        : 'border-rg-border2 focus-visible:border-rg-accent';
@endphp

<input
    name="{{ $name }}"
    type="{{ $type }}"
    @if (! is_null($value)) value="{{ $value }}" @endif
    @if (! is_null($placeholder)) placeholder="{{ $placeholder }}" @endif
    @disabled($disabled)
    @if ($error) aria-invalid="true" @endif
    {{ $attributes->class([$baseClass, $stateClass]) }}
>
