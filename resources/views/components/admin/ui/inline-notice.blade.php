@props([
    'tone' => 'info',
    'icon' => null,
    'strip' => false,
])

@php
    /*
     * Explains a rule or a consequence in place. Every tone has its own icon,
     * so the tone is never carried by colour alone. Danger is for irreversible
     * dialogs only. strip draws the full-width variant under a card toolbar.
     */
    $icons = [
        'info' => 'info',
        'warning' => 'circle-alert',
        'danger' => 'triangle-alert',
        'success' => 'circle-check',
    ];

    if (! array_key_exists($tone, $icons)) {
        throw new InvalidArgumentException("Unknown admin notice tone [{$tone}].");
    }
@endphp

<div {{ $attributes->class(['rg-admin-notice', "rg-admin-notice--{$tone}", 'rg-admin-notice--strip' => $strip]) }}>
    <x-admin.ui.icon :name="$icon ?? $icons[$tone]" class="rg-admin-notice__icon" />
    <div class="rg-admin-notice__body">{{ $slot }}</div>
    @isset($actions)
        <div class="rg-admin-notice__actions">{{ $actions }}</div>
    @endisset
</div>
