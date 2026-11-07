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
            const n = Number.parseFloat(String(input.value).replace(',', '.'))
            const seconds = Math.round(n * this.units[this.unit])

            if (Number.isNaN(n) || n < 0 || seconds === this.seconds) { input.value = this.value; return }

            Promise.resolve({!! $call !!}).then(() => { input.value = this.value })
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
    <div class="rg-admin-studio__units" role="radiogroup" aria-label="Unit">
        @foreach (array_keys($units) as $unit)
            <button type="button" role="radio" class="rg-admin-studio__unit" x-bind:aria-checked="unit === @js($unit)" x-on:click="unit = @js($unit)">{{ $unit }}</button>
        @endforeach
    </div>
</div>
