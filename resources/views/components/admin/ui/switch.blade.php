@props([
    'label',
    'id',
    'checked' => false,
    'on' => 'On',
    'off' => 'Off',
    'onHint' => null,
    'offHint' => null,
    'disabled' => false,
])

@php
    /*
     * FRM-06. An on/off setting that applies when its form is saved: a 36×20
     * track with a 16 knob, ink when on and gray-300 when off, the state in
     * words beside it (`on` / `off`) and a hint for each state under it.
     *
     * A real button with role="switch" and aria-checked, named by its label;
     * a disabled switch is really disabled, at 50%, and its hint says why.
     * The state is the component's own, exposed to wire:model and x-model
     * (x-modelable): wire:model="isActive". A switch that removes access
     * asks first when turned off; that is the screen's to do.
     */
    $checked = (bool) $checked;
@endphp

<div
    {{ $attributes->class(['rg-admin-field', 'rg-admin-switch-field']) }}
    x-data="{ checked: @js($checked) }"
    x-modelable="checked"
>
    <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>

    <div class="rg-admin-switch-field__control">
        <button
            type="button"
            id="{{ $id }}"
            role="switch"
            class="rg-admin-switch"
            aria-checked="{{ $checked ? 'true' : 'false' }}"
            x-bind:aria-checked="checked ? 'true' : 'false'"
            @if ($onHint !== null || $offHint !== null) aria-describedby="{{ $id }}-hint" @endif
            @disabled($disabled)
            x-on:click="checked = ! checked"
        >
            <span class="rg-admin-switch__knob" aria-hidden="true"></span>
        </button>
        <span class="rg-admin-switch-field__state" aria-hidden="true" x-text="checked ? @js($on) : @js($off)">{{ $checked ? $on : $off }}</span>
    </div>

    @if ($onHint !== null || $offHint !== null)
        <p id="{{ $id }}-hint" class="rg-admin-hint" x-text="checked ? @js($onHint ?? '') : @js($offHint ?? '')">{{ $checked ? $onHint : $offHint }}</p>
    @endif
</div>
