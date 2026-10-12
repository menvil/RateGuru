@props([
    'label',
    'id' => null,
    'checked' => false,
    'mixed' => false,
    'hideLabel' => false,
    'disabled' => false,
])

@php
    /*
     * FRM-07. A real checkbox, drawn the way the reference draws one: 18×18,
     * radius 5, an ink fill with a 12px white check, and a minus while it is
     * mixed. The input stays the control — the keyboard, a form, wire:model
     * and x-model reach it as they reach any checkbox — and the box is only
     * its picture.
     *
     * The label names it; `hide-label` keeps the name for assistive
     * technology only, as a row's selection box in a table needs. `mixed` is
     * a select-all box some of whose rows are selected: the input's
     * indeterminate state, which only script sets, so a screen that keeps it
     * live does so itself, e.g. x-effect="$el.indeterminate = …".
     */
@endphp

<label {{ $attributes->only(['class', 'style'])->class(['rg-admin-checkbox', 'rg-admin-checkbox--disabled' => $disabled]) }}>
    <input
        type="checkbox"
        @if ($id !== null) id="{{ $id }}" @endif
        class="rg-admin-checkbox__input"
        @checked($checked)
        @disabled($disabled)
        @if ($mixed) x-data x-init="$el.indeterminate = true" @endif
        {{ $attributes->except(['class', 'style']) }}
    />
    <span class="rg-admin-checkbox__box" aria-hidden="true">
        <x-admin.ui.icon name="check" :size="12" :stroke-width="3" class="rg-admin-checkbox__check" />
        <x-admin.ui.icon name="minus" :size="12" :stroke-width="3" class="rg-admin-checkbox__minus" />
    </span>
    <span @class(['rg-admin-checkbox__label', 'rg-admin-sr-only' => $hideLabel])>{{ $label }}</span>
</label>
