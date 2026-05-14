@props([
    'name',
    'type' => 'text',
    'value' => null,
    'placeholder' => null,
    'disabled' => false,
    'error' => false,
])

@php
    $baseClass = 'block w-full rounded-md border bg-zinc-950/80 px-3 py-2 text-sm text-zinc-100 placeholder:text-zinc-500 shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-950 disabled:cursor-not-allowed disabled:opacity-60';
    $stateClass = $error
        ? 'border-rose-400/70 focus-visible:border-rose-300 focus-visible:ring-rose-400/70'
        : 'border-zinc-700 focus-visible:border-sky-300 focus-visible:ring-sky-400/70';
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
