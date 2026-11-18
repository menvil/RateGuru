{{--
    The period picker of the report pages — a game's Analytics, Traffic
    sources (both with ChoosesReportPeriod) and Growth → Analytics: a trigger
    with the calendar, the period's name and its days, opening one panel with
    the page's presets beside a calendar of whole days, the date range field
    drawn in place. A preset is taken at once; so is a range, the moment its
    last day is clicked — there is no Apply and no Cancel. Escape or a click
    outside leave the period as it was. The calendar's limits only help: the
    server reads whatever it is sent, and a range it cannot read is the
    default preset.

    $picker comes from ChoosesReportPeriod::periodPicker(), or a page's own
    with the same keys; its optional 'most' is the longest range the page
    reads at a time. $id names the trigger and its panel; $align 'end' opens
    the panel from the trigger's right edge, for a picker at the right of its
    row.
--}}
@php($align ??= 'start')
<div
    @class(['rg-admin-period', 'rg-admin-period--end' => $align === 'end'])
    wire:key="{{ $id }}-{{ $picker['value'] }}-{{ $picker['from'] }}-{{ $picker['to'] }}"
    x-data="{
        open: false,
        close(returnFocus) {
            if (! this.open) {
                return
            }

            this.open = false

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
        takeRange(range) {
            this.open = false
            this.$wire.applyRange(range.from, range.to)
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

        {{-- Listened for here rather than on the calendar, whose own scope has an open and a close of its own. --}}
        <div class="rg-admin-period__range" x-on:rg-admin-range-chosen="takeRange($event.detail)">
            <p class="rg-admin-period__title" aria-hidden="true">Custom range</p>
            {{-- The period's own days to begin with, in the UTC days it is counted in. --}}
            <x-admin.ui.date-range-field
                inline
                :id="$id.'-calendar'"
                label="Custom range"
                :from="$picker['from']"
                :to="$picker['to']"
                :min="$picker['min']"
                :max="$picker['max']"
                :most="$picker['most'] ?? null"
                timezone="UTC"
            />
        </div>
    </div>
</div>
