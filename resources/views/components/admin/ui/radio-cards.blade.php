@props([
    'label',
    'options' => [],
    'value' => null,
])

@php
    /*
     * FRM-09. Two to four exclusive choices that each need a line of
     * explanation — a trust level, say: cards padded 12, radius 12, side by
     * side, each with a 16 ring, its label and what it means. The chosen one
     * has an ink border, the sunken fill and a 5px ink ring.
     *
     * options is value => ['label' => …, 'help' => …]. A radiogroup of radio
     * buttons, as the segmented control is: only the chosen card is a tab
     * stop, the arrow keys, Home and End move the choice with the focus.
     * The choice is the component's own state, exposed to wire:model and
     * x-model (x-modelable).
     */
    $values = array_map(strval(...), array_keys($options));

    if (count($values) < 2 || count($values) > 4) {
        throw new InvalidArgumentException('Radio cards offer two to four options.');
    }

    $value = in_array((string) $value, $values, true) ? (string) $value : $values[0];
@endphp

<div
    {{ $attributes->class(['rg-admin-radio-cards']) }}
    role="radiogroup"
    aria-label="{{ $label }}"
    style="--rg-admin-radio-cards-columns: {{ count($values) }}"
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
    @foreach ($options as $optionValue => $option)
        @php
            $optionValue = (string) $optionValue;
            $checked = $optionValue === $value;
        @endphp
        <button
            type="button"
            role="radio"
            class="rg-admin-radio-card"
            aria-checked="{{ $checked ? 'true' : 'false' }}"
            tabindex="{{ $checked ? '0' : '-1' }}"
            x-bind:aria-checked="selected === @js($optionValue) ? 'true' : 'false'"
            x-bind:tabindex="selected === @js($optionValue) ? 0 : -1"
            x-on:click="selected = @js($optionValue)"
        >
            <span class="rg-admin-radio-card__head">
                <span class="rg-admin-radio-card__ring" aria-hidden="true"></span>
                <span class="rg-admin-radio-card__label">{{ $option['label'] }}</span>
            </span>
            @if (filled($option['help'] ?? null))
                <span class="rg-admin-radio-card__help">{{ $option['help'] }}</span>
            @endif
        </button>
    @endforeach
</div>
