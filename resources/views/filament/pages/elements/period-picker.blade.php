{{--
    The period picker of the report pages that keep their period with
    ChoosesReportPeriod — a game's Analytics, Traffic sources: a trigger with
    the calendar, the period's name and its days, opening a panel with the
    page's presets and a range of whole days typed in. A preset is taken at
    once; a range when Apply is pressed. Cancel, Escape or a click outside
    leave the period as it was. The fields' limits only help: the server reads
    whatever it is sent, and a range it cannot read is the default preset.

    $picker comes from ChoosesReportPeriod::periodPicker(); $id names the
    trigger and its panel; $align 'end' opens the panel from the trigger's
    right edge, for a picker at the right of its row.
--}}
@php($align ??= 'start')
<div
    @class(['rg-admin-period', 'rg-admin-period--end' => $align === 'end'])
    wire:key="{{ $id }}-{{ $picker['value'] }}-{{ $picker['from'] }}-{{ $picker['to'] }}"
    x-data="{
        open: false,
        from: @js($picker['from']),
        to: @js($picker['to']),
        first: @js($picker['from']),
        last: @js($picker['to']),
        min: @js($picker['min']),
        max: @js($picker['max']),
        days() {
            if (! this.from || ! this.to) {
                return null
            }

            return Math.round((Date.parse(this.to) - Date.parse(this.from)) / 86400000) + 1
        },
        valid() {
            const days = this.days()

            return days !== null && days >= 1 && this.from >= this.min && this.to <= this.max
        },
        summary() {
            const days = this.days()

            if (days === null) {
                return 'Choose both days'
            }

            if (days < 1) {
                return 'The last day comes before the first'
            }

            if (this.to > this.max) {
                return 'No figures after today yet'
            }

            if (this.from < this.min) {
                return @js('Figures are kept '.$picker['kept'].' days')
            }

            return days + (days === 1 ? ' day' : ' days')
        },
        close(returnFocus) {
            if (! this.open) {
                return
            }

            this.open = false
            this.from = this.first
            this.to = this.last

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
        apply() {
            if (! this.valid()) {
                return
            }

            this.open = false
            this.$wire.applyRange(this.from, this.to)
        },
    }"
    x-on:click.outside="close(false)"
    x-on:keydown.escape.prevent.stop="close(true)"
>
    <button
        type="button"
        id="{{ $id }}"
        class="rg-admin-period__trigger"
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-controls="{{ $id }}-panel"
        x-ref="trigger"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        x-on:click="open ? close(true) : (open = true)"
    >
        <x-admin.ui.icon name="calendar" :size="16" class="rg-admin-period__icon" />
        <span class="rg-admin-period__name">{{ $picker['label'] }}</span>
        @if ($picker['dates'] !== null)
            <span class="rg-admin-period__dates">{{ $picker['dates'] }}</span>
        @endif
        <x-admin.ui.icon name="chevron-down" :size="16" class="rg-admin-period__icon" />
    </button>

    <div id="{{ $id }}-panel" role="dialog" aria-label="Period" class="rg-admin-period__panel" x-cloak x-show="open">
        <div class="rg-admin-period__presets" role="group" aria-label="Presets">
            @foreach ($picker['presets'] as $key => $label)
                <button
                    type="button"
                    class="rg-admin-period__preset"
                    aria-pressed="{{ $picker['value'] === $key ? 'true' : 'false' }}"
                    x-on:click="open = false"
                    wire:click="choosePeriod(@js($key))"
                >
                    <span>{{ $label }}</span>
                    <x-admin.ui.icon name="check" :size="16" class="rg-admin-period__check" />
                </button>
            @endforeach
        </div>

        <div class="rg-admin-period__range" role="group" aria-labelledby="{{ $id }}-range">
            <p id="{{ $id }}-range" class="rg-admin-period__title">Custom range</p>
            <div class="rg-admin-period__fields">
                <x-admin.ui.text-field :id="$id.'-from'" type="date" label="From" x-model="from" :min="$picker['min']" :max="$picker['max']" />
                <x-admin.ui.text-field :id="$id.'-to'" type="date" label="To" x-model="to" :min="$picker['min']" :max="$picker['max']" />
            </div>
            <div class="rg-admin-period__foot">
                <span class="rg-admin-period__summary" role="status" x-text="summary()"></span>
                <x-admin.ui.button size="sm" x-on:click="close(true)">Cancel</x-admin.ui.button>
                <x-admin.ui.button size="sm" variant="primary" x-bind:disabled="! valid()" x-on:click="apply()">Apply</x-admin.ui.button>
            </div>
        </div>
    </div>
</div>
