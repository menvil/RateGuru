@props([
    'label',
    'name' => null,
    'id' => null,
    'value' => '',
    'min' => null,
    'max' => null,
    'timezone' => null,
    'placeholder' => 'Choose a date and time',
    'hint' => null,
    'error' => null,
    'required' => null,
])

@php
    /*
     * A date and time, chosen in a calendar rather than typed: the prototype's
     * DateTimeField. A trigger with the moment and how far it is from now opens
     * a dialog with quick picks, a month — or, from its title, the months of a
     * year — the time in hours and five-minute steps with a few common times,
     * and the moment as it stands; Apply takes it, Cancel, Escape or a click
     * outside leave the value as it was.
     *
     * The value is the field's own text, Y-m-d\TH:i (or with seconds, kept
     * until the time is changed), a wall-clock moment in `timezone` — the
     * application's by default — as a datetime-local input holds one, and is
     * exposed to wire:model and x-model (x-modelable); min and max, in the same
     * form, bound what Apply takes. What is taken is the server's to validate.
     *
     * Keyboard: Enter, Space or Down on the trigger opens the dialog with focus
     * on the chosen day; the arrows move a day or a week, Home and End to the
     * week's ends, Page Up and Page Down a month (with Shift a year), Enter or
     * Space chooses; Escape closes back to the trigger.
     */
    $id ??= 'rg-admin-field-'.\Illuminate\Support\Str::slug($name ?? $label);
    $timezone ??= (string) config('app.timezone');

    $parse = function (?string $text) use ($timezone): ?\Carbon\CarbonImmutable {
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $format) {
            try {
                $at = \Carbon\CarbonImmutable::createFromFormat('!'.$format, (string) $text, $timezone);
            } catch (\Carbon\Exceptions\InvalidFormatException) {
                continue;
            }

            if ($at !== null && $at->format($format) === $text) {
                return $at;
            }
        }

        return null;
    };

    // The trigger as the browser draws it, so it reads the same before the script runs.
    $now = \Carbon\CarbonImmutable::now($timezone);
    $chosen = $parse($value);
    $long = fn (\Carbon\CarbonImmutable $at): string => $at->format($at->second === 0 ? 'D d M Y · H:i' : 'D d M Y · H:i:s');
    // Wall-clock seconds, as the browser counts them: a day is always 24 hours.
    $wall = fn (\Carbon\CarbonImmutable $at): int => \Carbon\CarbonImmutable::parse($at->format('Y-m-d H:i:s'), 'UTC')->getTimestamp();
    $relative = null;

    if ($chosen !== null) {
        $seconds = $wall($chosen) - $wall($now);
        $minutes = (int) round(abs($seconds) / 60);
        $days = (int) round($minutes / 1440);
        $count = fn (int $number, string $unit): string => $number.' '.$unit.($number === 1 ? '' : 's');
        $span = match (true) {
            $minutes < 60 => "{$minutes} min",
            $minutes < 1440 => ((int) round($minutes / 60)).' h',
            $days < 60 => $count($days, 'day'),
            $days < 730 => $count((int) round($days / 30.4375), 'month'),
            default => $count((int) round($days / 365.25), 'year'),
        };
        $relative = match (true) {
            abs($seconds) < 60 => ['text' => 'Now', 'past' => false],
            $seconds < 0 => ['text' => "{$span} ago · in the past", 'past' => true],
            default => ['text' => "in {$span}", 'past' => false],
        };
    }

    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null),
    ])->reject(fn (mixed $id): bool => $id === null || $id === '')->implode(' ');

    $weekdays = ['Monday' => 'Mo', 'Tuesday' => 'Tu', 'Wednesday' => 'We', 'Thursday' => 'Th', 'Friday' => 'Fr', 'Saturday' => 'Sa', 'Sunday' => 'Su'];
    $times = ['09:00', '12:00', '14:00', '18:00', '21:00'];
@endphp

<div
    {{ $attributes->except(['aria-describedby'])->class(['rg-admin-field', 'rg-admin-date-time']) }}
    data-now="{{ $now->format('Y-m-d\TH:i:s') }}"
    x-data="{
        value: @js((string) $value),
        min: @js($min),
        max: @js($max),
        minimum: null,
        maximum: null,
        serverNow: null,
        loadedAt: Date.now(),
        tick: Date.now(),
        timer: null,
        open: false,
        draft: null,
        focused: null,
        view: { year: 1970, month: 0 },
        choosing: 'days',
        dayLength: 86400000,
        weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        init() {
            this.serverNow = this.parse(this.$root.dataset.now)
            this.minimum = this.parse(this.min)
            this.maximum = this.parse(this.max)
            // How far the moment is from now, kept current while the page is open.
            this.timer = setInterval(() => this.tick = Date.now(), 30000)
            // A day's button shows another day once the month turns: the focus follows to the one Tab reaches.
            this.$watch('view', () => {
                if (this.$refs.grid.contains(document.activeElement)) {
                    this.$nextTick(() => this.focusDay())
                }
            })
        },
        destroy() {
            clearInterval(this.timer)
        },
        pad(number) {
            return String(number).padStart(2, '0')
        },
        {{-- Moments are wall-clock times in the field's time zone, counted as if in UTC: no daylight saving to step over. --}}
        parse(text) {
            const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(text ?? '')

            if (! match) {
                return null
            }

            const [year, month, date, hour, minute, second] = match.slice(1).map((part) => Number(part ?? 0))
            const at = Date.UTC(year, month - 1, date, hour, minute, second)

            return this.format(at) === text || this.format(at, true) === text ? at : null
        },
        format(at, seconds = false) {
            const date = new Date(at)
            const text = `${date.getUTCFullYear()}-${this.pad(date.getUTCMonth() + 1)}-${this.pad(date.getUTCDate())}T${this.pad(date.getUTCHours())}:${this.pad(date.getUTCMinutes())}`

            return seconds || date.getUTCSeconds() !== 0 ? `${text}:${this.pad(date.getUTCSeconds())}` : text
        },
        long(at) {
            const date = new Date(at)
            const seconds = date.getUTCSeconds() !== 0 ? `:${this.pad(date.getUTCSeconds())}` : ''

            return `${this.weekdays[date.getUTCDay()].slice(0, 3)} ${this.pad(date.getUTCDate())} ${this.months[date.getUTCMonth()].slice(0, 3)} ${date.getUTCFullYear()} · ${this.pad(date.getUTCHours())}:${this.pad(date.getUTCMinutes())}${seconds}`
        },
        now() {
            return this.serverNow + (this.tick - this.loadedAt)
        },
        relative(at) {
            const seconds = (at - this.now()) / 1000
            const minutes = Math.round(Math.abs(seconds) / 60)
            const days = Math.round(minutes / 1440)
            const count = (number, unit) => `${number} ${unit}${number === 1 ? '' : 's'}`
            const span = minutes < 60 ? `${minutes} min`
                : minutes < 1440 ? `${Math.round(minutes / 60)} h`
                : days < 60 ? count(days, 'day')
                : days < 730 ? count(Math.round(days / 30.4375), 'month')
                : count(Math.round(days / 365.25), 'year')

            if (Math.abs(seconds) < 60) {
                return { text: 'Now', past: false }
            }

            return seconds < 0 ? { text: `${span} ago · in the past`, past: true } : { text: `in ${span}`, past: false }
        },
        get chosen() {
            return this.parse(this.value)
        },
        allowed(at) {
            return (this.minimum === null || at >= this.minimum) && (this.maximum === null || at <= this.maximum)
        },
        clamp(at) {
            return Math.min(this.maximum ?? at, Math.max(this.minimum ?? at, at))
        },
        dayOf(at) {
            return at - (((at % this.dayLength) + this.dayLength) % this.dayLength)
        },
        daysIn(year, month) {
            return new Date(Date.UTC(year, month + 1, 0)).getUTCDate()
        },
        dayAllowed(day) {
            return (this.minimum === null || day + this.dayLength > this.minimum) && (this.maximum === null || day <= this.maximum)
        },
        monthAllowed(year, month) {
            return (this.minimum === null || Date.UTC(year, month + 1, 1) > this.minimum) && (this.maximum === null || Date.UTC(year, month, 1) <= this.maximum)
        },
        yearAllowed(year) {
            return (this.minimum === null || Date.UTC(year + 1, 0, 1) > this.minimum) && (this.maximum === null || Date.UTC(year, 0, 1) <= this.maximum)
        },
        shiftMonths(day, by) {
            const date = new Date(day)
            const month = date.getUTCMonth() + by
            const year = date.getUTCFullYear() + Math.floor(month / 12)
            const inYear = ((month % 12) + 12) % 12

            return Date.UTC(year, inYear, Math.min(date.getUTCDate(), this.daysIn(year, inYear)))
        },
        presets() {
            const now = this.now()
            const today = this.dayOf(now)
            const at = (days, hour) => today + days * this.dayLength + hour * 3600000

            return [
                { label: 'Now', moment: now - (((now % 60000) + 60000) % 60000) },
                { label: 'Today 18:00', moment: at(0, 18) },
                { label: 'Tomorrow 09:00', moment: at(1, 9) },
                { label: 'In 2 days', moment: at(2, 14) },
                { label: 'A week ago', moment: at(-7, 14) },
                { label: 'A month ago', moment: this.shiftMonths(today, -1) + 14 * 3600000 },
            ]
        },
        weeks() {
            const first = Date.UTC(this.view.year, this.view.month, 1)
            const lead = (new Date(first).getUTCDay() + 6) % 7
            const cells = [...Array(lead).fill(null), ...Array.from({ length: this.daysIn(this.view.year, this.view.month) }, (_, index) => first + index * this.dayLength)]

            while (cells.length % 7 !== 0) {
                cells.push(null)
            }

            return Array.from({ length: cells.length / 7 }, (_, row) => cells.slice(row * 7, row * 7 + 7))
        },
        inView(day) {
            const date = new Date(day)

            return date.getUTCFullYear() === this.view.year && date.getUTCMonth() === this.view.month
        },
        {{-- The one day of the month the Tab key reaches: the one last moved to, the chosen one, or the first that can be chosen. --}}
        focusTarget() {
            for (const day of [this.focused, this.draft === null ? null : this.dayOf(this.draft)]) {
                if (day !== null && this.inView(day) && this.dayAllowed(day)) {
                    return day
                }
            }

            return this.weeks().flat().find((day) => day !== null && this.dayAllowed(day)) ?? null
        },
        isChosen(day) {
            return this.draft !== null && this.dayOf(this.draft) === day
        },
        isToday(day) {
            return this.dayOf(this.now()) === day
        },
        dayLabel(day) {
            const date = new Date(day)

            return `${this.weekdays[date.getUTCDay()]} ${date.getUTCDate()} ${this.months[date.getUTCMonth()]} ${date.getUTCFullYear()}${this.isToday(day) ? ', today' : ''}`
        },
        get title() {
            return this.choosing === 'days' ? `${this.months[this.view.month]} ${this.view.year}` : String(this.view.year)
        },
        canStep(by) {
            if (this.choosing === 'months') {
                return this.yearAllowed(this.view.year + by)
            }

            const date = new Date(Date.UTC(this.view.year, this.view.month + by, 1))

            return this.monthAllowed(date.getUTCFullYear(), date.getUTCMonth())
        },
        step(by) {
            const date = new Date(Date.UTC(this.view.year + (this.choosing === 'months' ? by : 0), this.view.month + (this.choosing === 'days' ? by : 0), 1))

            this.view = { year: date.getUTCFullYear(), month: date.getUTCMonth() }
        },
        toggleMonths() {
            this.choosing = this.choosing === 'days' ? 'months' : 'days'

            if (this.choosing === 'days') {
                this.$nextTick(() => this.focusDay())
            }
        },
        chooseMonth(month) {
            this.view = { year: this.view.year, month }
            this.choosing = 'days'
            this.$nextTick(() => this.focusDay())
        },
        focusDay() {
            this.$refs.grid.querySelector(`[data-day='${this.focusTarget()}']`)?.focus()
        },
        moveFocus(event) {
            const from = this.focusTarget()

            if (from === null) {
                return
            }

            const weekday = (new Date(from).getUTCDay() + 6) % 7
            const by = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -weekday, End: 6 - weekday }[event.key]
            let day

            if (by !== undefined) {
                day = from + by * this.dayLength
            } else if (event.key === 'PageUp' || event.key === 'PageDown') {
                day = this.shiftMonths(from, (event.key === 'PageUp' ? -1 : 1) * (event.shiftKey ? 12 : 1))
            } else {
                return
            }

            event.preventDefault()

            if (! this.dayAllowed(day)) {
                return
            }

            const date = new Date(day)

            this.focused = day
            this.view = { year: date.getUTCFullYear(), month: date.getUTCMonth() }
            this.$nextTick(() => this.focusDay())
        },
        pickDay(day) {
            this.draft = day + (this.draft === null ? 0 : this.draft - this.dayOf(this.draft))
            this.focused = day
        },
        choose(moment) {
            const date = new Date(moment)

            this.draft = moment
            this.focused = this.dayOf(moment)
            this.view = { year: date.getUTCFullYear(), month: date.getUTCMonth() }
            this.choosing = 'days'
        },
        get hour() {
            return this.draft === null ? 0 : new Date(this.draft).getUTCHours()
        },
        get minute() {
            return this.draft === null ? 0 : new Date(this.draft).getUTCMinutes()
        },
        setTime(hour, minute) {
            this.draft = this.dayOf(this.draft) + hour * 3600000 + minute * 60000
        },
        stepHour(by) {
            this.setTime((this.hour + by + 24) % 24, this.minute)
        },
        stepMinute(by) {
            const minute = this.minute
            const next = by > 0 ? minute - (minute % 5) + 5 : (minute % 5 !== 0 ? minute - (minute % 5) : minute - 5)

            this.setTime(this.hour, (next + 60) % 60)
        },
        isTime(hour, minute) {
            return this.draft !== null && this.draft - this.dayOf(this.draft) === hour * 3600000 + minute * 60000
        },
        get summary() {
            if (this.draft === null) {
                return ''
            }

            if (! this.allowed(this.draft)) {
                return this.minimum !== null && this.maximum !== null
                    ? `Choose a moment from ${this.long(this.minimum)} to ${this.long(this.maximum)}.`
                    : (this.minimum !== null ? `Choose ${this.long(this.minimum)} or later.` : `Choose ${this.long(this.maximum)} or earlier.`)
            }

            return `${this.long(this.draft)} · ${this.relative(this.draft).text}`
        },
        bounds() {
            return {
                ceiling: Math.max(0, ...[...document.querySelectorAll('[data-admin-ceiling]')].map((bar) => bar.getBoundingClientRect().bottom)),
                floor: Math.min(window.innerHeight, ...[...document.querySelectorAll('[data-admin-floor]')].map((bar) => bar.getBoundingClientRect().top)),
            }
        },
        {{--
            Under the trigger, or over it when only there is room for the whole dialog; with room on neither side,
            wherever it is whole between the bars kept at the window's top and foot, over the trigger if it must —
            never taller than that room, nor past the window's sides.
        --}}
        place() {
            const box = this.$refs.trigger.getBoundingClientRect()
            const { ceiling, floor } = this.bounds()
            const height = this.$refs.dialog.offsetHeight
            const room = floor - ceiling - 16
            const top = box.bottom + 8 + height <= floor - 8
                ? box.bottom + 8
                : (box.top - 8 - height >= ceiling + 8 ? box.top - 8 - height : Math.max(ceiling + 8, floor - 8 - height))

            // Set at once rather than bound, so the focus that follows finds the dialog where it is going to be.
            Object.assign(this.$refs.dialog.style, {
                top: `${Math.round(top - this.$refs.dialog.offsetParent.getBoundingClientRect().top)}px`,
                left: `${Math.max(16 - box.left, Math.min(0, window.innerWidth - 16 - box.left - this.$refs.dialog.offsetWidth))}px`,
                maxHeight: height > room ? `${Math.max(240, room)}px` : '',
            })
        },
        show() {
            this.tick = Date.now()
            const start = this.chosen ?? this.clamp(this.presets()[0].moment)
            const date = new Date(start)

            this.draft = start
            this.focused = this.dayOf(start)
            this.view = { year: date.getUTCFullYear(), month: date.getUTCMonth() }
            this.choosing = 'days'
            Object.assign(this.$refs.dialog.style, { top: '', left: '', maxHeight: '' })
            this.open = true
            this.$nextTick(() => {
                this.place()
                this.$nextTick(() => this.focusDay())
            })
        },
        close(returnFocus) {
            if (! this.open) {
                return
            }

            this.open = false
            this.draft = null
            this.focused = null

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
        apply() {
            if (this.draft === null || ! this.allowed(this.draft)) {
                return
            }

            this.value = this.format(this.draft)
            this.close(true)
        },
    }"
    x-modelable="value"
    x-on:click.outside="close(false)"
    x-on:focusout="if (open && $event.relatedTarget && ! $root.contains($event.relatedTarget)) close(false)"
    x-on:keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); close(true) }"
>
    <div class="rg-admin-field__head">
        <label for="{{ $id }}" id="{{ $id }}-label" class="rg-admin-label">{{ $label }}</label>
        @if ($required === true)
            <span class="rg-admin-field__tag rg-admin-field__tag--required">Required</span>
        @elseif ($required === false)
            <span class="rg-admin-field__tag">Optional</span>
        @endif
    </div>

    <div class="rg-admin-date-time__control">
        <button
            type="button"
            id="{{ $id }}"
            @class(['rg-admin-date-time__trigger', 'rg-admin-date-time__trigger--invalid' => $error !== null])
            aria-haspopup="dialog"
            aria-expanded="false"
            aria-controls="{{ $id }}-dialog"
            aria-labelledby="{{ $id }}-label {{ $id }}-value"
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            x-ref="trigger"
            x-bind:aria-expanded="open ? 'true' : 'false'"
            x-on:click="open ? close(true) : show()"
            x-on:keydown.arrow-down.prevent="if (! open) show()"
        >
            <x-admin.ui.icon name="calendar" :size="16" class="rg-admin-date-time__icon" />
            <span id="{{ $id }}-value" class="rg-admin-date-time__text">
                <span
                    @class(['rg-admin-date-time__value', 'rg-admin-date-time__value--empty' => $chosen === null])
                    x-bind:class="{ 'rg-admin-date-time__value--empty': chosen === null }"
                    x-text="chosen === null ? @js($placeholder) : long(chosen)"
                >{{ $chosen !== null ? $long($chosen) : $placeholder }}</span>
                <span
                    @class(['rg-admin-date-time__relative', 'rg-admin-date-time__relative--past' => $relative['past'] ?? false])
                    x-bind:class="{ 'rg-admin-date-time__relative--past': chosen !== null && relative(chosen).past }"
                    x-show="chosen !== null"
                    x-text="chosen === null ? '' : relative(chosen).text"
                    @if ($chosen === null) style="display: none" @endif
                >{{ $relative['text'] ?? '' }}</span>
            </span>
            <x-admin.ui.icon name="chevron-down" :size="16" class="rg-admin-date-time__chevron" />
        </button>

        {{-- Drawn by the script alone: the server's markup of it never changes. --}}
        <div
            id="{{ $id }}-dialog"
            role="dialog"
            aria-labelledby="{{ $id }}-label"
            class="rg-admin-date-time__popover"
            x-ref="dialog"
            wire:ignore
            x-cloak
            x-show="open"
            {{-- A press anywhere in the dialog keeps the focus where it is, so the dialog stays open. --}}
            x-on:mousedown="$event.preventDefault()"
        >
            <div class="rg-admin-date-time__presets" role="group" aria-label="Quick picks">
                <template x-for="preset in presets()" :key="preset.label">
                    <button
                        type="button"
                        class="rg-admin-date-time__preset"
                        x-bind:aria-pressed="draft === preset.moment ? 'true' : 'false'"
                        x-bind:disabled="! allowed(preset.moment)"
                        x-on:click="choose(preset.moment)"
                    >
                        <span x-text="preset.label"></span>
                        <x-admin.ui.icon name="check" :size="14" class="rg-admin-date-time__preset-check" />
                    </button>
                </template>
            </div>

            <div class="rg-admin-date-time__main">
                <div class="rg-admin-date-time__calendar">
                    <div class="rg-admin-date-time__nav">
                        <x-admin.ui.icon-button
                            icon="chevron-left"
                            label="Previous month"
                            variant="ghost"
                            size="sm"
                            x-bind:aria-label="choosing === 'days' ? 'Previous month' : 'Previous year'"
                            x-bind:title="choosing === 'days' ? 'Previous month' : 'Previous year'"
                            x-bind:disabled="! canStep(-1)"
                            x-on:click="step(-1)"
                        />
                        <button
                            type="button"
                            id="{{ $id }}-title"
                            class="rg-admin-date-time__title"
                            x-bind:aria-expanded="choosing === 'months' ? 'true' : 'false'"
                            x-bind:aria-label="choosing === 'days' ? `${title}, choose another month or year` : `${title}, back to the days`"
                            x-on:click="toggleMonths()"
                        >
                            <span aria-live="polite" x-text="title"></span>
                            <x-admin.ui.icon name="chevron-down" :size="14" class="rg-admin-date-time__title-chevron" />
                        </button>
                        <x-admin.ui.icon-button
                            icon="chevron-right"
                            label="Next month"
                            variant="ghost"
                            size="sm"
                            x-bind:aria-label="choosing === 'days' ? 'Next month' : 'Next year'"
                            x-bind:title="choosing === 'days' ? 'Next month' : 'Next year'"
                            x-bind:disabled="! canStep(1)"
                            x-on:click="step(1)"
                        />
                    </div>

                    <div role="grid" class="rg-admin-date-time__grid" aria-labelledby="{{ $id }}-title" x-ref="grid" x-show="choosing === 'days'" x-on:keydown="moveFocus($event)">
                        <div role="row" class="rg-admin-date-time__week">
                            @foreach ($weekdays as $weekday => $short)
                                <span role="columnheader" class="rg-admin-date-time__weekday" aria-label="{{ $weekday }}">{{ $short }}</span>
                            @endforeach
                        </div>
                        <template x-for="(week, row) in weeks()" :key="row">
                            <div role="row" class="rg-admin-date-time__week">
                                <template x-for="(day, column) in week" :key="column">
                                    <div role="gridcell" class="rg-admin-date-time__cell" x-bind:aria-selected="day !== null && isChosen(day) ? 'true' : 'false'">
                                        <template x-if="day !== null">
                                            <button
                                                type="button"
                                                class="rg-admin-date-time__day"
                                                x-bind:data-day="day"
                                                x-bind:tabindex="day === focusTarget() ? 0 : -1"
                                                x-bind:aria-label="dayLabel(day)"
                                                x-bind:aria-current="isToday(day) ? 'date' : false"
                                                x-bind:disabled="! dayAllowed(day)"
                                                x-bind:class="{
                                                    'rg-admin-date-time__day--chosen': isChosen(day),
                                                    'rg-admin-date-time__day--today': isToday(day),
                                                    'rg-admin-date-time__day--past': day < dayOf(now()),
                                                }"
                                                x-on:click="pickDay(day)"
                                                x-text="new Date(day).getUTCDate()"
                                            ></button>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>

                    <div class="rg-admin-date-time__months" role="group" x-bind:aria-label="`Months of ${view.year}`" x-show="choosing === 'months'">
                        <template x-for="(name, month) in months" :key="month">
                            <button
                                type="button"
                                class="rg-admin-date-time__month"
                                x-bind:aria-label="`${name} ${view.year}`"
                                x-bind:aria-pressed="month === view.month ? 'true' : 'false'"
                                x-bind:disabled="! monthAllowed(view.year, month)"
                                x-on:click="chooseMonth(month)"
                                x-text="name.slice(0, 3)"
                            ></button>
                        </template>
                    </div>
                </div>

                <div class="rg-admin-date-time__time" role="group" aria-label="Time">
                    <div class="rg-admin-date-time__clock">
                        <span class="rg-admin-date-time__time-label" aria-hidden="true">Time</span>
                        <x-admin.ui.icon-button icon="minus" label="Hour earlier" variant="ghost" size="sm" x-on:click="stepHour(-1)" />
                        <span class="rg-admin-date-time__digits" x-text="pad(hour)">00</span>
                        <x-admin.ui.icon-button icon="plus" label="Hour later" variant="ghost" size="sm" x-on:click="stepHour(1)" />
                        <span class="rg-admin-date-time__digits" aria-hidden="true">:</span>
                        <x-admin.ui.icon-button icon="minus" label="5 minutes earlier" variant="ghost" size="sm" x-on:click="stepMinute(-1)" />
                        <span class="rg-admin-date-time__digits" x-text="pad(minute)">00</span>
                        <x-admin.ui.icon-button icon="plus" label="5 minutes later" variant="ghost" size="sm" x-on:click="stepMinute(1)" />
                    </div>
                    <div class="rg-admin-date-time__times">
                        @foreach ($times as $time)
                            @php([$hour, $minute] = array_map(intval(...), explode(':', $time)))
                            <button
                                type="button"
                                class="rg-admin-date-time__chip"
                                aria-pressed="false"
                                x-bind:aria-pressed="isTime({{ $hour }}, {{ $minute }}) ? 'true' : 'false'"
                                x-on:click="setTime({{ $hour }}, {{ $minute }})"
                            >{{ $time }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="rg-admin-date-time__footer">
                    <p
                        class="rg-admin-date-time__summary"
                        aria-live="polite"
                        x-bind:class="{
                            'rg-admin-date-time__summary--past': draft !== null && allowed(draft) && relative(draft).past,
                            'rg-admin-date-time__summary--invalid': draft !== null && ! allowed(draft),
                        }"
                        x-text="summary"
                    ></p>
                    <div class="rg-admin-date-time__actions">
                        <x-admin.ui.button size="sm" x-on:click="close(true)">Cancel</x-admin.ui.button>
                        <x-admin.ui.button size="sm" variant="primary" x-bind:disabled="draft === null || ! allowed(draft)" x-on:click="apply()">Apply</x-admin.ui.button>
                    </div>
                </div>
            </div>
        </div>
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
