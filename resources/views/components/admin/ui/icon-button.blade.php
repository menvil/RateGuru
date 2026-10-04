@props([
    'icon',
    'label',
    'variant' => 'outline',
    'size' => 'md',
    'href' => null,
    'type' => 'button',
    'disabled' => false,
])

@php
    if (! in_array($variant, ['outline', 'ghost'], true)) {
        throw new InvalidArgumentException("Unknown admin icon button variant [{$variant}].");
    }

    if (! in_array($size, ['sm', 'md'], true)) {
        throw new InvalidArgumentException("Unknown admin icon button size [{$size}].");
    }

    // An icon-only control is named by its label, read aloud and shown as a tooltip.
    $isLink = $href !== null && ! $disabled;
    $iconSize = $size === 'sm' ? 16 : 18;
    $classes = ['rg-admin-icon-button', "rg-admin-icon-button--{$variant}", "rg-admin-icon-button--{$size}"];
@endphp

@if ($isLink)
    <a href="{{ $href }}" aria-label="{{ $label }}" title="{{ $label }}" {{ $attributes->class($classes) }}>
        <x-admin.ui.icon :name="$icon" :size="$iconSize" />
    </a>
@else
    <button type="{{ $type }}" aria-label="{{ $label }}" title="{{ $label }}" @disabled($disabled) {{ $attributes->class($classes) }}>
        <x-admin.ui.icon :name="$icon" :size="$iconSize" />
    </button>
@endif
