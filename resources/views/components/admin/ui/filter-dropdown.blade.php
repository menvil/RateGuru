@props([
    'label',
    'options' => [],
    'value' => '',
    'id' => 'rg-admin-filter',
])

@php
    /*
     * FRM-10. A secondary filter next to the search: a button that reads
     * “Field: value” and opens a 240 wide menu of the values, the chosen one
     * checked, each with an optional count.
     *
     * options is a list of ['value' => …, 'label' => …, 'trigger' => …?,
     * 'count' => …?, 'liveCount' => …?]. trigger is what the button shows for
     * that value when it differs from the menu's label (“All” for “All
     * sections”); liveCount is an Alpine expression for a count that changes
     * on the page, evaluated in the screen's scope.
     *
     * The value is the component's own state, exposed to x-model
     * (x-modelable): x-model="section". A menu button: Enter, Space or Down
     * opens it on the checked value, Up and Down, Home and End move through
     * it, Enter or Space chooses, Escape closes it back to the button, and
     * Tab or a click outside closes it.
     */
    $values = array_map(fn (array $option): string => (string) $option['value'], $options);
    $value = in_array((string) $value, $values, true) ? (string) $value : ($values[0] ?? '');
    $triggers = collect($options)->mapWithKeys(fn (array $option): array => [(string) $option['value'] => $option['trigger'] ?? $option['label']])->all();
@endphp

<div
    {{ $attributes->class(['rg-admin-filter-dropdown']) }}
    x-data="{
        selected: @js($value),
        expanded: false,
        triggers: @js($triggers),
        items() {
            return [...this.$refs.menu.querySelectorAll('[role=menuitemradio]')]
        },
        show() {
            this.expanded = true
            this.$nextTick(() => (this.items().find((item) => item.getAttribute('aria-checked') === 'true') ?? this.items()[0])?.focus())
        },
        close(returnFocus) {
            if (! this.expanded) {
                return
            }

            this.expanded = false

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
        step(by) {
            const items = this.items()
            const at = items.indexOf(document.activeElement)

            items[(at + by + items.length) % items.length]?.focus()
        },
        pick(value) {
            this.selected = value
            this.close(true)
        },
    }"
    x-modelable="selected"
    x-on:click.outside="close(false)"
>
    <button
        type="button"
        id="{{ $id }}"
        class="rg-admin-button rg-admin-button--secondary rg-admin-button--md rg-admin-filter-dropdown__trigger"
        aria-haspopup="menu"
        aria-expanded="false"
        aria-controls="{{ $id }}-menu"
        x-ref="trigger"
        x-bind:aria-expanded="expanded ? 'true' : 'false'"
        x-on:click="expanded ? close(true) : show()"
        x-on:keydown.arrow-down.prevent="show()"
    >
        <span>{{ $label }}: <span x-text="triggers[selected]">{{ $triggers[$value] ?? '' }}</span></span>
        <x-admin.ui.icon name="chevron-down" :size="16" />
    </button>

    <div
        id="{{ $id }}-menu"
        role="menu"
        aria-labelledby="{{ $id }}"
        class="rg-admin-menu rg-admin-filter-dropdown__menu"
        x-ref="menu"
        x-cloak
        x-show="expanded"
        x-on:keydown.arrow-down.prevent="step(1)"
        x-on:keydown.arrow-up.prevent="step(-1)"
        x-on:keydown.home.prevent="items()[0]?.focus()"
        x-on:keydown.end.prevent="items().at(-1)?.focus()"
        x-on:keydown.escape.prevent.stop="close(true)"
        x-on:keydown.tab="close(false)"
    >
        @foreach ($options as $option)
            @php
                $optionValue = (string) $option['value'];
            @endphp
            <button
                type="button"
                role="menuitemradio"
                tabindex="-1"
                class="rg-admin-filter-dropdown__item"
                aria-checked="{{ $optionValue === $value ? 'true' : 'false' }}"
                x-bind:aria-checked="selected === @js($optionValue) ? 'true' : 'false'"
                x-on:click="pick(@js($optionValue))"
            >
                <span class="rg-admin-filter-dropdown__label">{{ $option['label'] }}</span>
                @if (isset($option['liveCount']))
                    <span class="rg-admin-filter-dropdown__count" x-text="{{ $option['liveCount'] }}">{{ $option['count'] ?? '' }}</span>
                @elseif (isset($option['count']))
                    <span class="rg-admin-filter-dropdown__count">{{ $option['count'] }}</span>
                @endif
                <x-admin.ui.icon name="check" :size="16" class="rg-admin-filter-dropdown__check" />
            </button>
        @endforeach
    </div>
</div>
