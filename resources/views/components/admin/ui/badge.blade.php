@props([
    'tone' => 'neutral',
    'dot' => false,
    'pill' => false,
    'icon' => null,
])

@php
    /*
     * A badge knows tones, not statuses. Which tone a record state gets is
     * decided in one mapping per screen, never inside this component.
     */
    if (! in_array($tone, ['success', 'warning', 'danger', 'neutral', 'outline', 'info'], true)) {
        throw new InvalidArgumentException("Unknown admin badge tone [{$tone}].");
    }
@endphp

<span {{ $attributes->class(['rg-admin-badge', "rg-admin-badge--{$tone}", 'rg-admin-badge--pill' => $pill]) }}>
    @if ($dot)
        <span class="rg-admin-badge__dot" aria-hidden="true"></span>
    @endif
    @if ($icon)
        <x-admin.ui.icon :name="$icon" :size="12" />
    @endif
    {{ $slot }}
</span>
