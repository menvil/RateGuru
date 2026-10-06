@props([
    'variant' => 'secondary',
    'size' => 'md',
    'icon' => null,
    'trailingIcon' => null,
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    if (! in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true)) {
        throw new InvalidArgumentException("Unknown admin button variant [{$variant}].");
    }

    if (! in_array($size, ['sm', 'md'], true)) {
        throw new InvalidArgumentException("Unknown admin button size [{$size}].");
    }

    // A disabled link is no longer a link: it renders as a real disabled button.
    $isLink = $href !== null && ! $disabled;
    $iconSize = $size === 'sm' ? 14 : 16;
    $classes = ['rg-admin-button', "rg-admin-button--{$variant}", "rg-admin-button--{$size}"];
@endphp

@if ($isLink)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" :size="$iconSize" />
        @endif
        {{ $slot }}
        @if ($trailingIcon)
            <x-admin.ui.icon :name="$trailingIcon" :size="$iconSize" />
        @endif
    </a>
@else
    <button type="{{ $type }}" @disabled($disabled) {{ $attributes->class($classes) }}>
        @if ($icon)
            <x-admin.ui.icon :name="$icon" :size="$iconSize" />
        @endif
        {{ $slot }}
        @if ($trailingIcon)
            <x-admin.ui.icon :name="$trailingIcon" :size="$iconSize" />
        @endif
    </button>
@endif
