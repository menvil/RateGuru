@props([
    'label',
    'id',
    'value' => null,
    'min' => null,
    'max' => null,
    'hint' => null,
    'error' => null,
    'range' => null,
    'unit' => null,
])

@php
    /*
     * FRM-05. A whole number with a minus and a plus: 40 high, 132 wide, the
     * buttons 36 wide and named after the field (“Decrease sort order”). The
     * number is typed straight in: focusing it selects it, so what is typed
     * replaces it, and ↑ and ↓ step it as the buttons do. A step starts from
     * what the field holds, stays within min and max, and tells the input it
     * changed, so wire:model or x-model sees a step as it sees typing.
     * Attributes go to the input (wire:model="sortOrder"); what is typed is
     * the server's to validate.
     *
     * Optional: `range`, the bounds said at the right of the label (“0–40”),
     * and `unit`, what the number counts, after the control (“comments”).
     */
    $describedBy = $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null);
    $name = \Illuminate\Support\Str::lower($label);
@endphp

<div class="rg-admin-field rg-admin-stepper-field">
    @if ($range !== null)
        <div class="rg-admin-stepper-field__head">
            <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>
            <span class="rg-admin-stepper-field__range">{{ $range }}</span>
        </div>
    @else
        <label for="{{ $id }}" class="rg-admin-label">{{ $label }}</label>
    @endif

    <div class="rg-admin-stepper-field__row">
        <div
            @class(['rg-admin-stepper', 'rg-admin-stepper--invalid' => $error !== null])
            x-data="{
                min: @js($min),
                max: @js($max),
                // Focused by a click: the click's mouseup would put the caret
                // in and lose the selection focusing made, so that one is held.
                justFocused: false,
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
                title="Type a number, or step it with − and + or the arrow keys"
                x-ref="input"
                x-on:focus="$el.select(); justFocused = true"
                x-on:mouseup="if (justFocused) $event.preventDefault(); justFocused = false"
                x-on:blur="justFocused = false"
                x-on:keydown.arrow-up.prevent="step(1)"
                x-on:keydown.arrow-down.prevent="step(-1)"
                @if ($error !== null) aria-invalid="true" @endif
                @if ($describedBy !== null) aria-describedby="{{ $describedBy }}" @endif
                {{ $attributes }}
            />
            <button type="button" class="rg-admin-stepper__button" aria-label="Increase {{ $name }}" title="Increase {{ $name }}" x-on:click="step(1)">
                <x-admin.ui.icon name="plus" :size="14" />
            </button>
        </div>
        @if ($unit !== null)
            <span class="rg-admin-stepper-field__unit">{{ $unit }}</span>
        @endif
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
