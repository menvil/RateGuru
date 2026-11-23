@props([
    'name',
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'disabled' => false,
    'error' => false,
])

@php
    $baseClass = 'block h-10 w-full rounded-rgControl border bg-rg-card2 px-3 text-[13.5px] text-rg-text placeholder:text-rg-muted shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25 disabled:cursor-not-allowed disabled:bg-rg-surface disabled:text-rg-muted disabled:opacity-45';
    $stateClass = $error
        ? 'border-[rgba(239,68,68,0.65)] focus-visible:border-[rgba(239,68,68,0.85)]'
        : 'border-rg-border2 focus-visible:border-rg-accent';

    // An address is written left to right in every language. In a field read
    // right to left its "://" and a closing "/" would turn round — "https://"
    // showing as "//:https" — so a URL field reads left to right everywhere.
    $direction = $type === 'url' && ! $attributes->has('dir') ? 'ltr' : null;
@endphp

<input
    name="{{ $name }}"
    type="{{ $type }}"
    @if (! is_null($value)) value="{{ $value }}" @endif
    @if (! is_null($placeholder)) placeholder="{{ $placeholder }}" @endif
    @disabled($disabled)
    @if ($error) aria-invalid="true" @endif
    @if (! is_null($direction)) dir="{{ $direction }}" @endif
    {{ $attributes->class([$baseClass, $stateClass]) }}
>
