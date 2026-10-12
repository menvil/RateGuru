@props([
    'label',
    'id',
    'value' => null,
    'min' => null,
    'max' => null,
    'hint' => null,
    'error' => null,
])

@php
    /*
     * FRM-05. A whole number with a minus and a plus: 40 high, 132 wide, the
     * buttons 36 wide and named after the field (“Decrease sort order”). The
     * buttons step from what the field holds, within min and max, and tell
     * the input it changed, so wire:model or x-model sees a step as it sees
     * typing. Attributes go to the input (wire:model="sortOrder"); what is
     * typed is the server's to validate.
     */
    $describedBy = $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null);
    $name = \Illuminate\Support\Str::lower($label);
@endphp

<div class="rg-admin-field rg-admin-stepper-field">
    <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>

    <div
        @class(['rg-admin-stepper', 'rg-admin-stepper--invalid' => $error !== null])
        x-data="{
            min: @js($min),
            max: @js($max),
            step(by) {
                const input = this.$refs.input
                const current = Number.parseInt(input.value, 10)
                let next = (Number.isNaN(current) ? (this.min ?? 0) : current) + by

                if (this.min !== null) next = Math.max(this.min, next)
                if (this.max !== null) next = Math.min(this.max, next)

                input.value = String(next)
                input.dispatchEvent(new Event('input', { bubbles: true }))
                input.dispatchEvent(new Event('change', { bubbles: true }))
            },
        }"
    >
        <button type="button" class="rg-admin-stepper__button" aria-label="Decrease {{ $name }}" title="Decrease {{ $name }}" x-on:click="step(-1)">
            <x-admin.ui.icon name="minus" :size="14" />
        </button>
        <input
            id="{{ $id }}"
            type="text"
            inputmode="numeric"
            value="{{ $value }}"
            class="rg-admin-stepper__control"
            autocomplete="off"
            x-ref="input"
            @if ($error !== null) aria-invalid="true" @endif
            @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif
            {{ $attributes }}
        />
        <button type="button" class="rg-admin-stepper__button" aria-label="Increase {{ $name }}" title="Increase {{ $name }}" x-on:click="step(1)">
            <x-admin.ui.icon name="plus" :size="14" />
        </button>
    </div>

    @if ($error !== null)
        <p id="{{ $id }}-error" class="rg-admin-error">
            <x-admin.ui.icon name="circle-alert" :size="12" />
            {{ $error }}
        </p>
    @elseif ($hint !== null)
        <p id="{{ $id }}-hint" class="rg-admin-hint">{{ $hint }}</p>
    @endif
</div>
