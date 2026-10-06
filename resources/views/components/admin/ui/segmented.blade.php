@props([
    'label',
    'options' => [],
    'value' => null,
    'compact' => false,
])

@php
    /*
     * FRM-08. Two to four mutually exclusive options on one track, such as
     * Missing only / All. A radio group: Tab reaches the checked option, the
     * arrow keys, Home and End move the choice and the focus with it, and
     * Space or Enter on an option chooses it.
     *
     * options is value => label. The choice is the component's own state,
     * exposed to x-model (x-modelable), so a screen binds it to its filter:
     *
     *     <x-admin.ui.segmented label="Show" :options="['missing' => 'Missing only', 'all' => 'All']" x-model="mode" />
     *
     * The checked option is told apart by more than colour: a raised white
     * surface, weight 500 and aria-checked.
     */
    $values = array_map(strval(...), array_keys($options));

    if (count($values) < 2 || count($values) > 4) {
        throw new InvalidArgumentException('A segmented control has two to four options.');
    }

    $value = in_array((string) $value, $values, true) ? (string) $value : $values[0];
@endphp

<div
    {{ $attributes->class(['rg-admin-segmented', 'rg-admin-segmented--compact' => $compact]) }}
    role="radiogroup"
    aria-label="{{ $label }}"
    x-data="{
        selected: @js($value),
        values: @js($values),
        move(by) {
            const at = Math.max(0, this.values.indexOf(this.selected))

            this.choose(this.values[(at + by + this.values.length) % this.values.length])
        },
        choose(value) {
            this.selected = value
            this.$nextTick(() => this.$root.querySelector('[aria-checked=true]')?.focus())
        },
    }"
    x-modelable="selected"
    x-on:keydown.arrow-right.prevent="move(1)"
    x-on:keydown.arrow-down.prevent="move(1)"
    x-on:keydown.arrow-left.prevent="move(-1)"
    x-on:keydown.arrow-up.prevent="move(-1)"
    x-on:keydown.home.prevent="choose(values[0])"
    x-on:keydown.end.prevent="choose(values[values.length - 1])"
>
    @foreach ($options as $optionValue => $optionLabel)
        @php
            $optionValue = (string) $optionValue;
            $checked = $optionValue === $value;
        @endphp
        <button
            type="button"
            role="radio"
            class="rg-admin-segmented__option"
            aria-checked="{{ $checked ? 'true' : 'false' }}"
            tabindex="{{ $checked ? '0' : '-1' }}"
            x-bind:aria-checked="selected === @js($optionValue) ? 'true' : 'false'"
            x-bind:tabindex="selected === @js($optionValue) ? 0 : -1"
            x-on:click="selected = @js($optionValue)"
        >
            {{-- The weight-500 copy reserves the checked width, so choosing an option never moves the others. --}}
            <span class="rg-admin-segmented__label" data-label="{{ $optionLabel }}">{{ $optionLabel }}</span>
        </button>
    @endforeach
</div>
