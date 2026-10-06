@props([
    'label',
    'options' => [],
    'value' => null,
    'id' => 'rg-admin-combobox',
    'searchPlaceholder' => 'Search',
    'empty' => 'Nothing matches.',
])

@php
    /*
     * FRM-11. Choosing one value from a long list — the target language among
     * thirty or more. A 52 high trigger with an overline and the chosen value
     * opens a 420 wide list with a search first; the search filters the list
     * in the browser, and the list scrolls past 340.
     *
     * options is a list of ['value' => …, 'label' => …, 'leading' => …?
     * (a flag), 'meta' => …?, 'trailing' => …?, 'trailingTone' => 'success'?,
     * 'badge' => ['label' => …, 'tone' => …, 'dot' => bool]?, 'search' => …?,
     * 'live' => ['meta' => …, 'trailing' => …, 'complete' => …]?]. live holds
     * Alpine expressions, evaluated in the screen's scope, for an option whose
     * figures change on the page — the language being translated.
     *
     * Keyboard: Enter, Space, Down or Up on the trigger opens the list with
     * focus in its search; Down and Up move the active option, Enter chooses
     * it, Escape closes the list back to the trigger, and Tab or a click
     * outside closes it. The search is an ARIA combobox over a listbox; the
     * active option is its aria-activedescendant.
     *
     * Choosing dispatches a cancelable `choose` event ({ value }) and then
     * takes the value — unless a listener cancelled the event, which is how a
     * screen asks before a change, or makes the change on the server:
     *
     *     x-on:choose="$event.preventDefault(); switchTo($event.detail.value)"
     *
     * The value is exposed to x-model (x-modelable).
     */
    $options = array_values(array_map(fn (array $option): array => [
        ...$option,
        'value' => (string) $option['value'],
        'search' => mb_strtolower($option['search'] ?? $option['label']),
    ], $options));
    $values = array_column($options, 'value');
    $value = in_array((string) $value, $values, true) ? (string) $value : ($values[0] ?? null);
    $selected = $options[(int) array_search($value, $values, true)] ?? null;
    $display = collect($options)->mapWithKeys(fn (array $option, int $index): array => [$option['value'] => [
        'id' => "{$id}-option-{$index}",
        'label' => $option['label'],
        'leading' => $option['leading'] ?? '',
        'badge' => $option['badge'] ?? null,
    ]])->all();
@endphp

<div
    {{ $attributes->class(['rg-admin-combobox']) }}
    x-data="{
        selected: @js($value),
        display: @js($display),
        searches: @js(array_column($options, 'search', 'value')),
        expanded: false,
        term: '',
        active: null,
        init() {
            this.$watch('term', () => this.$nextTick(() => {
                const shown = this.shown()

                if (! shown.some((option) => option.dataset.value === this.active)) {
                    this.active = shown[0]?.dataset.value ?? null
                }
            }))
        },
        matches(search) {
            const needle = this.term.trim().toLowerCase()

            return needle === '' || search.includes(needle)
        },
        shown() {
            return [...this.$refs.list.querySelectorAll('[role=option]')].filter((option) => option.style.display !== 'none')
        },
        get anyShown() {
            return Object.values(this.searches).some((search) => this.matches(search))
        },
        show() {
            this.expanded = true
            this.term = ''
            this.active = this.selected
            this.$nextTick(() => {
                this.$refs.search.focus()
                this.reveal()
            })
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
        move(by) {
            const shown = this.shown()
            const at = shown.findIndex((option) => option.dataset.value === this.active)
            const next = shown[at === -1 ? 0 : Math.min(shown.length - 1, Math.max(0, at + by))]

            this.active = next?.dataset.value ?? null
            this.$nextTick(() => this.reveal())
        },
        reveal() {
            this.$refs.list.querySelector('[data-active=true]')?.scrollIntoView({ block: 'nearest' })
        },
        pick(value) {
            if (value === null || value === undefined) {
                return
            }

            this.close(true)

            if (value === this.selected) {
                return
            }

            const event = new CustomEvent('choose', { detail: { value }, bubbles: true, cancelable: true })

            if (this.$root.dispatchEvent(event)) {
                this.selected = value
            }
        },
    }"
    x-modelable="selected"
    x-on:click.outside="close(false)"
    x-on:focusout="if (expanded && ! $root.contains($event.relatedTarget)) close(false)"
>
    <button
        type="button"
        id="{{ $id }}-trigger"
        class="rg-admin-combobox__trigger"
        aria-haspopup="listbox"
        aria-expanded="false"
        aria-controls="{{ $id }}-popover"
        x-ref="trigger"
        x-bind:aria-expanded="expanded ? 'true' : 'false'"
        x-on:click="expanded ? close(true) : show()"
        x-on:keydown.arrow-down.prevent="show()"
        x-on:keydown.arrow-up.prevent="show()"
    >
        <span class="rg-admin-combobox__leading" aria-hidden="true" x-text="display[selected]?.leading">{{ $selected['leading'] ?? '' }}</span>
        <span class="rg-admin-combobox__text">
            <span class="rg-admin-combobox__overline">{{ $label }}</span>
            <span class="rg-admin-combobox__value" x-text="display[selected]?.label">{{ $selected['label'] ?? '' }}</span>
        </span>
        @if (($selected['badge'] ?? null) !== null)
            <span
                class="rg-admin-badge rg-admin-badge--{{ $selected['badge']['tone'] }}"
                x-bind:class="'rg-admin-badge--' + display[selected]?.badge?.tone"
                x-show="display[selected]?.badge"
            >
                <span class="rg-admin-badge__dot" aria-hidden="true" @if (! ($selected['badge']['dot'] ?? false)) style="display: none" @endif x-show="display[selected]?.badge?.dot"></span>
                <span x-text="display[selected]?.badge?.label">{{ $selected['badge']['label'] }}</span>
            </span>
        @endif
        <x-admin.ui.icon name="chevrons-up-down" :size="16" class="rg-admin-combobox__chevron" />
    </button>

    <div
        id="{{ $id }}-popover"
        class="rg-admin-combobox__popover"
        x-cloak
        x-show="expanded"
        x-on:keydown.escape.prevent.stop="close(true)"
        {{-- A press anywhere in the list keeps focus in the search, so the list stays open. --}}
        x-on:mousedown="if (! $event.target.closest('input')) $event.preventDefault()"
    >
        <div class="rg-admin-combobox__search">
            <x-admin.ui.search-field
                :placeholder="$searchPlaceholder"
                :id="$id.'-search'"
                :name="$id.'-search'"
                style="width: 100%"
                role="combobox"
                aria-expanded="true"
                aria-controls="{{ $id }}-listbox"
                aria-autocomplete="list"
                x-ref="search"
                x-model="term"
                x-bind:aria-activedescendant="display[active]?.id ?? false"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="pick(active)"
                x-on:keydown.tab="close(false)"
            />
        </div>

        <div id="{{ $id }}-listbox" role="listbox" aria-label="{{ $label }}" class="rg-admin-combobox__list" x-ref="list">
            @foreach ($options as $index => $option)
                <div
                    id="{{ $id }}-option-{{ $index }}"
                    role="option"
                    class="rg-admin-combobox__option"
                    data-value="{{ $option['value'] }}"
                    aria-selected="{{ $option['value'] === $value ? 'true' : 'false' }}"
                    x-bind:aria-selected="selected === @js($option['value']) ? 'true' : 'false'"
                    x-bind:data-active="active === @js($option['value']) ? 'true' : 'false'"
                    x-show="matches(@js($option['search']))"
                    x-on:mousemove="active = @js($option['value'])"
                    x-on:click="pick(@js($option['value']))"
                >
                    @isset($option['leading'])
                        <span class="rg-admin-combobox__option-leading" aria-hidden="true">{{ $option['leading'] }}</span>
                    @endisset
                    <span class="rg-admin-combobox__option-text">
                        <span class="rg-admin-combobox__option-label">{{ $option['label'] }}</span>
                        @isset($option['meta'])
                            <span class="rg-admin-combobox__option-meta" @isset($option['live']['meta']) x-text="{{ $option['live']['meta'] }}" @endisset>{{ $option['meta'] }}</span>
                        @endisset
                    </span>
                    @isset($option['trailing'])
                        <span
                            @class(['rg-admin-combobox__option-trailing', 'rg-admin-combobox__option-trailing--success' => ($option['trailingTone'] ?? null) === 'success'])
                            @isset($option['live']['trailing']) x-text="{{ $option['live']['trailing'] }}" @endisset
                            @isset($option['live']['complete']) x-bind:class="{ 'rg-admin-combobox__option-trailing--success': {{ $option['live']['complete'] }} }" @endisset
                        >{{ $option['trailing'] }}</span>
                    @endisset
                    <x-admin.ui.icon name="check" :size="16" class="rg-admin-combobox__option-check" />
                </div>
            @endforeach
        </div>

        {{-- Always present, so a screen reader hears it the moment the search matches nothing. --}}
        <p class="rg-admin-combobox__empty" role="status" x-text="anyShown ? '' : @js($empty)"></p>
    </div>
</div>
