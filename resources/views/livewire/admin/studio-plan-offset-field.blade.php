{{--
    A moment after the post as a Timing tab sets it: a number in minutes,
    hours or days, saved as soon as it changes. $seconds is the moment now,
    $label names it for a screen reader, and $call is the JavaScript that
    saves a new one — `seconds` holds it, and a promise it returns is waited
    for before the field shows the moment it has. $disabled keeps it from
    changing; $disabledWhile, when given, is a JavaScript condition that does
    too.
--}}
@php
    $units = \App\Livewire\Admin\StudioPlanDiscussionEditor::UNITS;
    $disabledWhile ??= null;
@endphp
<div
    class="rg-admin-studio__offset"
    x-data="{
        seconds: {{ $seconds }},
        unit: @js(\App\Livewire\Admin\StudioPlanDiscussionEditor::unitFor($seconds)),
        units: @js($units),
        get value() { return String(Math.round((this.seconds / this.units[this.unit]) * 100) / 100) },
        commit(input) {
            // The whole field as a number — `2h` is not 2 — or it goes back to the moment it has.
            const text = String(input.value).trim().replace(',', '.')
            const n = text === '' ? Number.NaN : Number(text)
            const seconds = Math.round(n * this.units[this.unit])

            if (! Number.isFinite(n) || n < 0 || seconds === this.seconds) { input.value = this.value; return }

            // Saved or not, the field shows the moment the plan has.
            Promise.resolve({!! $call !!}).then(() => { input.value = this.value }, () => { input.value = this.value })
        },
        // The units are one radio group: one tab stop, the arrows, Home and End move the choice.
        move(by) {
            const order = Object.keys(this.units)
            const at = Math.max(0, order.indexOf(this.unit))

            this.choose(order[(at + by + order.length) % order.length])
        },
        choose(unit) {
            this.unit = unit
            this.$nextTick(() => this.$root.querySelector('[role=radio][aria-checked=true]')?.focus())
        },
    }"
>
    <input
        type="text"
        inputmode="decimal"
        class="rg-admin-studio__offset-input"
        aria-label="{{ $label }}"
        :value="value"
        x-on:change="commit($event.target)"
        @disabled($disabled)
        @if ($disabledWhile !== null)
            x-bind:disabled="{{ $disabled ? 'true' : 'false' }} || {{ $disabledWhile }}"
        @endif
    />
    <div
        class="rg-admin-studio__units"
        role="radiogroup"
        aria-label="Unit"
        x-on:keydown.arrow-right.prevent="move(1)"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-left.prevent="move(-1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.home.prevent="choose(Object.keys(units)[0])"
        x-on:keydown.end.prevent="choose(Object.keys(units).at(-1))"
    >
        @foreach (array_keys($units) as $unit)
            <button type="button" role="radio" class="rg-admin-studio__unit" x-bind:aria-checked="unit === @js($unit)" x-bind:tabindex="unit === @js($unit) ? 0 : -1" x-on:click="unit = @js($unit)">{{ $unit }}</button>
        @endforeach
    </div>
</div>
