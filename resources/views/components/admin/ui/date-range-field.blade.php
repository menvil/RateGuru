@props([
    'label',
    'id' => null,
    'from' => '',
    'to' => '',
    'min' => null,
    'max' => null,
    'most' => null,
    'openEnd' => false,
    'inline' => false,
    'fromModel' => null,
    'toModel' => null,
    'timezone' => null,
    'placeholder' => null,
    'hint' => null,
    'error' => null,
    'required' => null,
])

@php
    /*
     * A range of whole days, chosen as a booking site has it: one calendar —
     * two months side by side where there is room for them, one where there
     * isn't. The first day clicked starts the range, the next ends it, and a
     * day before the start starts it again from there. While only the start
     * is chosen, the days from it to the one under the pointer or the focus
     * are lightly filled; a range chosen shows its two ends filled and the
     * days between lightly. Choosing the end takes the range at once — there
     * is no Apply, no Cancel and nothing to clear — and Escape or a click
     * outside leave what was taken before.
     *
     * The values are days, Y-m-d: `from` and `to`. `min` and `max` bound the
     * days that may be chosen, and `most` how many days a range may hold at a
     * time: while the end is being chosen, the days past it can't end the
     * range, and the calendar says so. With `from-model` and `to-model` each
     * is bound to a Livewire property as wire:model binds one, deferred to
     * the next request; every range taken is also announced with the
     * `rg-admin-range-chosen` event, its detail { from, to }.
     *
     * `open-end`: the end may be left open, as a price that runs until it is
     * changed. The start is taken the moment it is clicked, the end emptied;
     * closing then keeps a range with no end, and No end empties the end of a
     * range that has one.
     *
     * `inline`: the calendar alone, drawn where it stands — in a panel of its
     * own, as the period picker's — with nothing to open or close; Escape or
     * a click outside it drop a start chosen without an end. Otherwise a
     * trigger with the range and how many days it holds opens the calendar
     * under it, or over it when only there is room for all of it, within the
     * window's bars and whatever panel around the field clips what overflows
     * — a drawer's scrolling body — as the date field places its dialog.
     *
     * The month grids are the date field's own parts (rg-admin-date-time__*),
     * so the two calendars look alike.
     *
     * Keyboard: Enter, Space or Down on the trigger opens the calendar on the
     * range's start; the arrows move a day or a week, Home and End to the
     * week's ends, Page Up and Page Down a month (with Shift a year), Enter or
     * Space chooses the day; Escape closes back to the trigger.
     */
    $id ??= 'rg-admin-field-'.\Illuminate\Support\Str::slug($label);
    $timezone ??= (string) config('app.timezone');
    $openEnd = (bool) $openEnd;
    $inline = (bool) $inline;
    $most = $most === null ? null : (int) $most;
    $placeholder ??= $openEnd ? 'Choose the first day' : 'Choose the days';

    $day = function (mixed $text): ?\Carbon\CarbonImmutable {
        try {
            $at = \Carbon\CarbonImmutable::createFromFormat('!Y-m-d', (string) $text, 'UTC');
        } catch (\Carbon\Exceptions\InvalidFormatException) {
            return null;
        }

        return $at !== null && $at->format('Y-m-d') === $text ? $at : null;
    };

    // The trigger as the browser draws it, so it reads the same before the script runs.
    $first = $day($from);
    $last = $first !== null ? $day($to) : null;
    $last = $last !== null && $last->greaterThanOrEqualTo($first) ? $last : null;
    $long = fn (\Carbon\CarbonImmutable $at): string => $at->format('d M Y');
    $shown = match (true) {
        $first === null => $placeholder,
        $last === null => 'From '.$long($first).($openEnd ? ' · no end' : ''),
        $first->equalTo($last) => $long($last),
        default => $first->format($first->year === $last->year ? 'd M' : 'd M Y').' — '.$long($last),
    };
    $length = $last !== null ? (int) $first->diffInDays($last) + 1 : null;
    $lengthText = $length === null ? '' : $length.' '.($length === 1 ? 'day' : 'days');

    $describedBy = collect([
        $attributes->get('aria-describedby'),
        $error !== null ? "{$id}-error" : ($hint !== null ? "{$id}-hint" : null),
    ])->reject(fn (mixed $id): bool => $id === null || $id === '')->implode(' ');

    // Bound to Livewire, or held here.
    $state = fn (?string $model, mixed $value): string => $model !== null
        ? '$wire.$entangle('.\Illuminate\Support\Js::from($model).')'
        : (string) \Illuminate\Support\Js::from((string) $value);

    $weekdays = ['Monday' => 'Mo', 'Tuesday' => 'Tu', 'Wednesday' => 'We', 'Thursday' => 'Th', 'Friday' => 'Fr', 'Saturday' => 'Sa', 'Sunday' => 'Su'];
@endphp

<div
    {{ $attributes->except(['aria-describedby'])->class(['rg-admin-field' => ! $inline, 'rg-admin-date-range', 'rg-admin-date-range--inline' => $inline]) }}
    data-now="{{ \Carbon\CarbonImmutable::now($timezone)->format('Y-m-d\TH:i:s') }}"
    @if ($inline) role="group" aria-label="{{ $label }}" @endif
    x-data="{
        from: {!! $state($fromModel, $from) !!},
        to: {!! $state($toModel, $to) !!},
        min: @js($min),
        max: @js($max),
        most: @js($most),
        openEnd: @js($openEnd),
        inline: @js($inline),
        placeholder: @js($placeholder),
        minimum: null,
        maximum: null,
        serverNow: null,
        loadedAt: Date.now(),
        open: false,
        start: null,
        end: null,
        pointer: null,
        focused: null,
        first: { year: 1970, month: 0 },
        count: 1,
        observer: null,
        dayLength: 86400000,
        weekdays: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        init() {
            const now = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})$/.exec(this.$root.dataset.now).slice(1).map(Number)

            this.serverNow = Date.UTC(now[0], now[1] - 1, now[2], now[3], now[4], now[5])
            this.minimum = this.parseDay(this.min)
            this.maximum = this.parseDay(this.max)
            this.reset()

            // Drawn in its panel, it shows as many months as the panel has room for.
            if (this.inline) {
                this.observer = new ResizeObserver(() => this.fit(this.$root.clientWidth))
                this.observer.observe(this.$root)
            }
        },
        destroy() {
            this.observer?.disconnect()
        },
        pad(number) {
            return String(number).padStart(2, '0')
        },
        {{-- Days are the starts of wall-clock days, counted as if in UTC: no daylight saving to step over. --}}
        parseDay(text) {
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text ?? '')

            if (! match) {
                return null
            }

            const day = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]))

            return this.format(day) === text ? day : null
        },
        format(day) {
            const date = new Date(day)

            return `${date.getUTCFullYear()}-${this.pad(date.getUTCMonth() + 1)}-${this.pad(date.getUTCDate())}`
        },
        short(day) {
            const date = new Date(day)

            return `${this.pad(date.getUTCDate())} ${this.months[date.getUTCMonth()].slice(0, 3)}`
        },
        long(day) {
            return `${this.short(day)} ${new Date(day).getUTCFullYear()}`
        },
        {{-- Two days as the period picker names them: “10 Sep — 09 Oct 2026”, each with its year where the years differ. --}}
        span(start, end) {
            if (start === end) {
                return this.long(end)
            }

            return `${new Date(start).getUTCFullYear() === new Date(end).getUTCFullYear() ? this.short(start) : this.long(start)} — ${this.long(end)}`
        },
        length(start, end) {
            const days = Math.round((end - start) / this.dayLength) + 1

            return `${days} ${days === 1 ? 'day' : 'days'}`
        },
        {{-- What was taken: the start, and the end when there is one from the start on. --}}
        get takenStart() {
            return this.parseDay(this.from)
        },
        get takenEnd() {
            const start = this.takenStart
            const end = this.parseDay(this.to)

            return start !== null && end !== null && end >= start ? end : null
        },
        get shown() {
            const start = this.takenStart
            const end = this.takenEnd

            if (start === null) {
                return this.placeholder
            }

            return end === null ? `From ${this.long(start)}${this.openEnd ? ' · no end' : ''}` : this.span(start, end)
        },
        get shownLength() {
            return this.takenEnd === null ? '' : this.length(this.takenStart, this.takenEnd)
        },
        today() {
            return this.dayOf(this.serverNow + (Date.now() - this.loadedAt))
        },
        dayOf(at) {
            return at - (((at % this.dayLength) + this.dayLength) % this.dayLength)
        },
        daysIn(year, month) {
            return new Date(Date.UTC(year, month + 1, 0)).getUTCDate()
        },
        allowed(day) {
            return (this.minimum === null || day >= this.minimum) && (this.maximum === null || day <= this.maximum)
        },
        {{-- While the end is being chosen, a range holds no more than `most` days: the days past them can't end it. --}}
        reachable(day) {
            return this.allowed(day) && (this.most === null || this.start === null || this.end !== null || day <= this.start + (this.most - 1) * this.dayLength)
        },
        monthAllowed(year, month) {
            return (this.minimum === null || Date.UTC(year, month + 1, 1) > this.minimum) && (this.maximum === null || Date.UTC(year, month, 1) <= this.maximum)
        },
        monthAt(index) {
            const date = new Date(Date.UTC(this.first.year, this.first.month + index, 1))

            return { year: date.getUTCFullYear(), month: date.getUTCMonth() }
        },
        visible() {
            return Array.from({ length: this.count }, (_, index) => this.monthAt(index))
        },
        weeks(month) {
            const first = Date.UTC(month.year, month.month, 1)
            const lead = (new Date(first).getUTCDay() + 6) % 7
            const cells = [...Array(lead).fill(null), ...Array.from({ length: this.daysIn(month.year, month.month) }, (_, index) => first + index * this.dayLength)]

            while (cells.length % 7 !== 0) {
                cells.push(null)
            }

            return Array.from({ length: cells.length / 7 }, (_, row) => cells.slice(row * 7, row * 7 + 7))
        },
        offset(day) {
            const date = new Date(day)

            return (date.getUTCFullYear() - this.first.year) * 12 + date.getUTCMonth() - this.first.month
        },
        inView(day) {
            const offset = this.offset(day)

            return offset >= 0 && offset < this.count
        },
        {{--
            The months shown: from the range's start, or up to its end when the whole range doesn't fit, or from this
            month when there is no range — all within min and max where they leave room.
        --}}
        anchor() {
            const month = (day) => new Date(day).getUTCFullYear() * 12 + new Date(day).getUTCMonth()
            let index = month(this.start ?? Math.min(this.maximum ?? Infinity, Math.max(this.minimum ?? -Infinity, this.today())))

            if (this.start !== null && this.end !== null && month(this.end) - index >= this.count) {
                index = month(this.end) - this.count + 1
            }

            if (this.maximum !== null) {
                index = Math.min(index, month(this.maximum) - this.count + 1)
            }

            if (this.minimum !== null) {
                index = Math.max(index, month(this.minimum))
            }

            return { year: Math.floor(index / 12), month: index % 12 }
        },
        {{-- Two months where they fit side by side, one where they don't. --}}
        fit(room) {
            const count = room >= 472 ? 2 : 1

            if (count !== this.count) {
                this.count = count
                this.first = this.anchor()
            }
        },
        reset() {
            this.start = this.takenStart
            this.end = this.takenEnd
            this.pointer = null
            this.focused = null
            this.first = this.anchor()
        },
        {{-- The day the end would be while only the start is chosen: the one under the pointer or the focus, from the start on and within reach. --}}
        get reach() {
            if (this.start === null || this.end !== null || this.pointer === null || this.pointer < this.start || ! this.reachable(this.pointer)) {
                return null
            }

            return this.pointer
        },
        isEdge(day) {
            return day === this.start || day === this.end
        },
        isBetween(day) {
            const last = this.end ?? this.reach

            return this.start !== null && last !== null && day > this.start && day < last
        },
        isReach(day) {
            return this.end === null && day === this.reach && day !== this.start
        },
        isToday(day) {
            return this.today() === day
        },
        dayLabel(day) {
            const date = new Date(day)
            const role = day === this.start ? (this.end === day ? ', the start and the end' : ', the start') : (day === this.end ? ', the end' : '')

            return `${this.weekdays[date.getUTCDay()]} ${date.getUTCDate()} ${this.months[date.getUTCMonth()]} ${date.getUTCFullYear()}${this.isToday(day) ? ', today' : ''}${role}`
        },
        {{-- The one day the Tab key reaches: the one last moved to, the start, the end, today, or the first that can be chosen. --}}
        focusTarget() {
            for (const day of [this.focused, this.start, this.end, this.today()]) {
                if (day !== null && this.inView(day) && this.reachable(day)) {
                    return day
                }
            }

            return this.visible().flatMap((month) => this.weeks(month).flat()).find((day) => day !== null && this.reachable(day)) ?? null
        },
        focusDay() {
            this.$refs.months.querySelector(`[data-day='${this.focusTarget()}']`)?.focus()
        },
        canStep(by) {
            const month = this.monthAt(by < 0 ? -1 : this.count)

            return this.monthAllowed(month.year, month.month)
        },
        step(by) {
            // A day's button is gone or shows another day once the month turns: the focus follows to the one Tab reaches.
            const onDay = this.$refs.months.contains(document.activeElement) && document.activeElement.dataset.day !== undefined

            this.first = this.monthAt(by)

            if (onDay) {
                this.$nextTick(() => this.focusDay())
            }
        },
        shiftMonths(day, by) {
            const date = new Date(day)
            const month = date.getUTCMonth() + by
            const year = date.getUTCFullYear() + Math.floor(month / 12)
            const inYear = ((month % 12) + 12) % 12

            return Date.UTC(year, inYear, Math.min(date.getUTCDate(), this.daysIn(year, inYear)))
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

            if (! this.reachable(day)) {
                return
            }

            const offset = this.offset(day)

            this.focused = day
            this.pointer = day

            if (offset < 0 || offset >= this.count) {
                this.first = this.monthAt(offset < 0 ? offset : offset - this.count + 1)
            }

            this.$nextTick(() => this.focusDay())
        },
        pick(day) {
            if (! this.reachable(day)) {
                return
            }

            this.focused = day

            // A first day, or one before the start: the range starts again from it.
            if (this.start === null || this.end !== null || day < this.start) {
                this.start = day
                this.end = null

                if (this.openEnd) {
                    this.from = this.format(day)
                    this.to = ''
                    this.announce()
                }

                return
            }

            this.end = day
            this.from = this.format(this.start)
            this.to = this.format(day)
            this.announce()

            if (! this.inline) {
                this.close(true)
            }
        },
        noEnd() {
            this.end = null
            this.to = ''
            this.announce()
            this.close(true)
        },
        announce() {
            this.$dispatch('rg-admin-range-chosen', { from: this.from, to: this.to })
        },
        get status() {
            if (this.start === null) {
                return this.openEnd ? 'Pick the first day' : 'Pick the start date'
            }

            if (this.end === null) {
                return this.openEnd ? 'Pick the end date, or close for no end' : `From ${this.long(this.start)} · pick the end date`
            }

            return `${this.span(this.start, this.end)} · ${this.length(this.start, this.end)}`
        },
        {{--
            The room the calendar has: between the bars kept at the window's top and foot, inside the window's sides,
            and inside every panel around the field that clips what overflows it — a drawer's scrolling body.
        --}}
        bounds() {
            const bounds = {
                ceiling: Math.max(0, ...[...document.querySelectorAll('[data-admin-ceiling]')].map((bar) => bar.getBoundingClientRect().bottom)),
                floor: Math.min(window.innerHeight, ...[...document.querySelectorAll('[data-admin-floor]')].map((bar) => bar.getBoundingClientRect().top)),
                start: 16,
                end: window.innerWidth - 16,
            }

            for (let panel = this.$root.parentElement; panel !== null && panel !== document.body; panel = panel.parentElement) {
                const style = getComputedStyle(panel)
                const box = panel.getBoundingClientRect()

                if (style.overflowY !== 'visible') {
                    bounds.ceiling = Math.max(bounds.ceiling, box.top)
                    bounds.floor = Math.min(bounds.floor, box.bottom)
                }

                if (style.overflowX !== 'visible') {
                    bounds.start = Math.max(bounds.start, box.left)
                    bounds.end = Math.min(bounds.end, box.right)
                }
            }

            return bounds
        },
        {{-- Under the trigger, or over it when only there is room for all of it; else wherever it is whole in the room it has. --}}
        place() {
            const box = this.$refs.trigger.getBoundingClientRect()
            const { ceiling, floor, start, end } = this.bounds()
            const height = this.$refs.dialog.offsetHeight
            const room = floor - ceiling - 16
            const top = box.bottom + 8 + height <= floor - 8
                ? box.bottom + 8
                : (box.top - 8 - height >= ceiling + 8 ? box.top - 8 - height : Math.max(ceiling + 8, floor - 8 - height))

            Object.assign(this.$refs.dialog.style, {
                top: `${Math.round(top - this.$refs.dialog.offsetParent.getBoundingClientRect().top)}px`,
                left: `${Math.max(start - box.left, Math.min(0, end - box.left - this.$refs.dialog.offsetWidth))}px`,
                maxHeight: height > room ? `${Math.max(240, room)}px` : '',
            })
        },
        show() {
            const { start, end } = this.bounds()

            // Two months where the room has the width of the calendar that holds them.
            this.count = end - start >= 532 ? 2 : 1
            this.reset()
            Object.assign(this.$refs.dialog.style, { top: '', left: '', maxHeight: '' })
            this.open = true
            this.$nextTick(() => {
                this.place()
                this.$nextTick(() => this.focusDay())
            })
        },
        close(returnFocus) {
            if (this.inline || ! this.open) {
                return
            }

            this.open = false
            this.reset()

            if (returnFocus) {
                this.$refs.trigger.focus()
            }
        },
    }"
    @if ($inline)
        x-on:click.outside="reset()"
        x-on:keydown.escape="reset()"
    @else
        x-on:click.outside="close(false)"
        x-on:focusout="if (open && $event.relatedTarget && ! $root.contains($event.relatedTarget)) close(false)"
        x-on:keydown.escape="if (open) { $event.preventDefault(); $event.stopPropagation(); close(true) }"
    @endif
>
    @unless ($inline)
        <div class="rg-admin-field__head">
            <label for="{{ $id }}" id="{{ $id }}-label" class="rg-admin-label">{{ $label }}</label>
            @if ($required === true)
                <span class="rg-admin-field__tag rg-admin-field__tag--required">Required</span>
            @elseif ($required === false)
                <span class="rg-admin-field__tag">Optional</span>
            @endif
        </div>
    @endunless

    {{-- The trigger and the calendar hanging from it; drawn in place, the calendar alone. --}}
    @unless ($inline)
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
                        @class(['rg-admin-date-time__value', 'rg-admin-date-time__value--empty' => $first === null])
                        x-bind:class="{ 'rg-admin-date-time__value--empty': takenStart === null }"
                        x-text="shown"
                    >{{ $shown }}</span>
                    <span
                        class="rg-admin-date-time__relative"
                        x-show="shownLength !== ''"
                        x-text="shownLength"
                        @if ($lengthText === '') style="display: none" @endif
                    >{{ $lengthText }}</span>
                </span>
                <x-admin.ui.icon name="chevron-down" :size="16" class="rg-admin-date-time__chevron" />
            </button>
    @endunless

        {{-- Drawn by the script alone: the server's markup of it never changes. --}}
        <div
            @if ($inline)
                class="rg-admin-date-range__body"
            @else
                id="{{ $id }}-dialog"
                role="dialog"
                aria-labelledby="{{ $id }}-label"
                class="rg-admin-date-time__popover rg-admin-date-range__popover rg-admin-date-range__body"
                x-bind:class="{ 'rg-admin-date-range__popover--pair': count === 2 }"
                x-ref="dialog"
                x-cloak
                x-show="open"
                {{-- A press anywhere in the calendar keeps the focus where it is, so it stays open. --}}
                x-on:mousedown="$event.preventDefault()"
            @endif
            wire:ignore
        >
            <div class="rg-admin-date-range__months" x-ref="months" x-on:mouseleave="pointer = null">
                <template x-for="(month, index) in visible()" :key="index">
                    <div class="rg-admin-date-range__month">
                        <div class="rg-admin-date-time__nav">
                            <span class="rg-admin-date-range__step">
                                <x-admin.ui.icon-button icon="chevron-left" label="Previous month" variant="ghost" size="sm" x-show="index === 0" x-bind:disabled="! canStep(-1)" x-on:click="step(-1)" />
                            </span>
                            <span class="rg-admin-date-range__title" x-bind:id="'{{ $id }}-month-' + index" x-text="`${months[month.month]} ${month.year}`"></span>
                            <span class="rg-admin-date-range__step">
                                <x-admin.ui.icon-button icon="chevron-right" label="Next month" variant="ghost" size="sm" x-show="index === count - 1" x-bind:disabled="! canStep(1)" x-on:click="step(1)" />
                            </span>
                        </div>

                        <div role="grid" class="rg-admin-date-time__grid" x-bind:aria-labelledby="'{{ $id }}-month-' + index" x-on:keydown="moveFocus($event)">
                            <div role="row" class="rg-admin-date-time__week">
                                @foreach ($weekdays as $weekday => $short)
                                    <span role="columnheader" class="rg-admin-date-time__weekday" aria-label="{{ $weekday }}">{{ $short }}</span>
                                @endforeach
                            </div>
                            <template x-for="(week, row) in weeks(month)" :key="row">
                                <div role="row" class="rg-admin-date-time__week">
                                    <template x-for="(day, column) in week" :key="column">
                                        <div role="gridcell" class="rg-admin-date-time__cell" x-bind:aria-selected="day !== null && (isEdge(day) || (end !== null && isBetween(day))) ? 'true' : 'false'">
                                            <template x-if="day !== null">
                                                <button
                                                    type="button"
                                                    class="rg-admin-date-time__day"
                                                    x-bind:data-day="day"
                                                    x-bind:tabindex="day === focusTarget() ? 0 : -1"
                                                    x-bind:aria-label="dayLabel(day)"
                                                    x-bind:aria-current="isToday(day) ? 'date' : false"
                                                    x-bind:disabled="! reachable(day)"
                                                    x-bind:class="{
                                                        'rg-admin-date-time__day--today': isToday(day),
                                                        'rg-admin-date-range__day--edge': isEdge(day),
                                                        'rg-admin-date-range__day--between': isBetween(day),
                                                        'rg-admin-date-range__day--reach': isReach(day),
                                                    }"
                                                    x-on:mouseenter="pointer = day"
                                                    x-on:focus="pointer = day"
                                                    x-on:click="pick(day)"
                                                    x-text="new Date(day).getUTCDate()"
                                                ></button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            <div class="rg-admin-date-range__foot">
                <p class="rg-admin-date-range__status" aria-live="polite" x-text="status"></p>
                @if ($most !== null)
                    <span class="rg-admin-date-range__limit">Up to {{ $most }} days at a time</span>
                @endif
                @if ($openEnd)
                    <x-admin.ui.button size="sm" variant="ghost" class="rg-admin-date-range__no-end" x-show="end !== null" x-on:click="noEnd()">No end</x-admin.ui.button>
                @endif
            </div>
        </div>
    @unless ($inline)
        </div>

        @if ($error !== null)
            <p id="{{ $id }}-error" class="rg-admin-error">
                <x-admin.ui.icon name="circle-alert" :size="12" />
                {{ $error }}
            </p>
        @elseif ($hint !== null)
            <p id="{{ $id }}-hint" class="rg-admin-hint">{{ $hint }}</p>
        @endif
    @endunless
</div>
