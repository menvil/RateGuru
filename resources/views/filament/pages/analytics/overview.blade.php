@php
    /*
     * Analytics → Overview (OverviewTab), as the reference draws it: the
     * tiles, the acquisition funnel — with the way to the journey of one of
     * its people — beside the visitors of each hour, day or week with what
     * happened on them, the sources table — whose rows filter
     * the whole page — then what shared results brought, why the figures can
     * differ from an ad network's, and what RateGuru records. Nobody matching
     * the filter is said once, with nothing else drawn.
     */
    use App\Filament\Pages\GrowthAnalyticsPage;
@endphp

@if ($data['empty'])
    @include('filament.pages.analytics.elements.no-visitors', ['range' => $data['range'], 'filtered' => $data['filtered']])
@else
    <x-admin.ui.card flush clip data-testid="analytics-kpis">
        <dl class="rg-admin-analytics__kpis">
            @foreach ($data['kpis'] as $kpi)
                <div class="rg-admin-analytics__kpi">
                    <dt class="rg-admin-analytics__kpi-label">
                        {{ $kpi['label'] }}
                        <span class="rg-admin-analytics__shield" title="Counted by {{ site_name() }}"><x-admin.ui.icon name="shield-check" :size="12" /></span>
                    </dt>
                    <dd class="rg-admin-analytics__kpi-value">{{ $kpi['value'] }}</dd>
                    <dd class="rg-admin-analytics__kpi-unit">{{ $kpi['unit'] }}</dd>
                    @if ($kpi['change'] !== null)
                        <dd class="rg-admin-analytics__kpi-change">{{ $kpi['change'] }}</dd>
                    @endif
                    @if ($kpi['flag'] !== null)
                        <dd class="rg-admin-analytics__kpi-flag" data-testid="analytics-too-early">
                            <x-admin.ui.badge tone="warning" :title="$kpi['flag']['hint']">{{ $kpi['flag']['label'] }}</x-admin.ui.badge>
                            <span class="rg-admin-sr-only">{{ $kpi['flag']['hint'] }}</span>
                        </dd>
                    @endif
                </div>
            @endforeach
        </dl>
    </x-admin.ui.card>

    <div class="rg-admin-analytics__pair">
        <x-admin.ui.card title="Acquisition funnel" :description="$data['funnel']['note']" class="rg-admin-analytics__funnel-card" data-testid="analytics-funnel">
            {{-- Which journey is picked when it is followed: one of the people the filters keep. --}}
            <x-slot:aside>
                <a href="{{ GrowthAnalyticsPage::getUrl(['tab' => 'journeys']) }}" class="rg-admin-analytics__journey-link" x-on:click="if (! ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey)) { $event.preventDefault(); $wire.openExampleJourney() }" data-testid="analytics-example-journey">See an example journey<x-admin.ui.icon name="arrow-right" :size="13" /></a>
            </x-slot:aside>
            <ol class="rg-admin-analytics__funnel">
                @foreach ($data['funnel']['steps'] as $step)
                    <li class="rg-admin-analytics__step" title="{{ $step['hint'] }}">
                        <span class="rg-admin-analytics__step-name">
                            <span class="rg-admin-analytics__step-label">{{ $step['name'] }}</span>
                            <span class="rg-admin-analytics__track" aria-hidden="true"><span @class(['rg-admin-analytics__fill', 'rg-admin-analytics__fill--strong' => $step['strong']]) style="width: {{ $step['bar'] }}%"></span></span>
                        </span>
                        <span class="rg-admin-analytics__step-count">{{ $step['count'] }}</span>
                        <span class="rg-admin-analytics__step-rate">{{ $step['previous'] }}</span>
                        <span class="rg-admin-analytics__step-first">{{ $step['first'] }}</span>
                    </li>
                @endforeach
            </ol>
            <div class="rg-admin-analytics__step rg-admin-analytics__step--head" aria-hidden="true">
                <span></span>
                <span>Unique visitors</span>
                <span>Of previous</span>
                <span>Of visitors</span>
            </div>
        </x-admin.ui.card>

        <x-admin.ui.card :title="$data['chart']['title']" :description="$data['chart']['note']" data-testid="analytics-chart">
            {{--
                The readout says the count of the bar pointed at, tapped or arrowed to — the busiest one's while none is — so the exact
                figure never waits on a hover a phone can't do. The whole column is the target, not the painted bar. Each bar keeps its
                own name for a screen reader; the readout only repeats it. Keyed by the bars, a new period draws a new chart.
            --}}
            <div
                class="rg-admin-analytics__chart"
                wire:key="analytics-chart-{{ md5(json_encode($data['chart']['bars'])) }}"
                x-data="{
                    at: null,
                    count: {{ count($data['chart']['bars']) }},
                    pick(event) {
                        const box = this.$refs.bars.getBoundingClientRect()
                        this.at = Math.min(this.count - 1, Math.max(0, Math.floor((event.clientX - box.left) / box.width * this.count)))
                    },
                    step(by) {
                        this.at = this.at === null ? (by > 0 ? 0 : this.count - 1) : Math.min(this.count - 1, Math.max(0, this.at + by))
                    },
                    get readout() {
                        return this.at === null ? this.$refs.readout.dataset.peak : this.$refs.bars.children[this.at].dataset.title
                    },
                }"
                x-on:click.outside="at = null"
            >
                <p class="rg-admin-analytics__readout" x-ref="readout" data-peak="{{ $data['chart']['peak'] }}" x-text="readout" aria-live="polite" data-testid="analytics-chart-readout">{{ $data['chart']['peak'] }}</p>
                <div class="rg-admin-analytics__plot">
                    <div class="rg-admin-analytics__axis" aria-hidden="true">
                        <span class="rg-admin-analytics__axis-size">{{ collect($data['chart']['scale'])->last()['value'] ?? '' }}</span>
                        @foreach ($data['chart']['scale'] as $line)
                            <span class="rg-admin-analytics__axis-value" style="bottom: {{ $line['at'] }}%">{{ $line['value'] }}</span>
                        @endforeach
                    </div>
                    <div
                        class="rg-admin-analytics__field"
                        tabindex="0"
                        aria-label="{{ $data['chart']['title'] }}: arrow keys read each bar"
                        x-on:pointermove="pick($event)"
                        x-on:pointerdown="pick($event)"
                        x-on:pointerleave="if ($event.pointerType === 'mouse') at = null"
                        x-on:keydown.right.prevent="step(1)"
                        x-on:keydown.left.prevent="step(-1)"
                        x-on:keydown.home.prevent="at = 0"
                        x-on:keydown.end.prevent="at = count - 1"
                        x-on:keydown.escape="at = null"
                        x-on:blur="at = null"
                    >
                        @foreach ($data['chart']['scale'] as $line)
                            <span class="rg-admin-analytics__gridline" style="bottom: {{ $line['at'] }}%" aria-hidden="true"></span>
                        @endforeach
                        <div @class(['rg-admin-analytics__bars', 'rg-admin-analytics__bars--dense' => $data['chart']['dense']]) role="list" aria-label="{{ $data['chart']['title'] }}" x-ref="bars">
                            @foreach ($data['chart']['bars'] as $bar)
                                <span
                                    role="listitem"
                                    @class(['rg-admin-analytics__bar', 'rg-admin-analytics__bar--marked' => $bar['marked'], 'rg-admin-analytics__bar--to-come' => $bar['toCome']])
                                    x-bind:class="{ 'rg-admin-analytics__bar--chosen': at === {{ $loop->index }} }"
                                    style="height: {{ $bar['height'] }}%"
                                    data-title="{{ $bar['title'] }}"
                                ><span class="rg-admin-sr-only">{{ $bar['title'] }}</span></span>
                            @endforeach
                        </div>
                    </div>
                    <div class="rg-admin-analytics__ticks" aria-hidden="true">
                        @foreach ($data['chart']['ticks'] as $tick)
                            <span>{{ $tick }}</span>
                        @endforeach
                    </div>
                </div>
            </div>
            @if ($data['chart']['markers'] !== [])
                <ul class="rg-admin-analytics__markers" data-testid="analytics-markers">
                    @foreach ($data['chart']['markers'] as $marker)
                        <li class="rg-admin-analytics__marker">{{ $marker }}</li>
                    @endforeach
                </ul>
            @endif
        </x-admin.ui.card>
    </div>

    <x-admin.ui.card title="Traffic sources" :description="$data['sourcesNote']" flush class="rg-admin-analytics__sources-card" data-testid="analytics-sources">
        <x-slot:aside>
            <x-admin.ui.segmented label="Sort" compact :options="GrowthAnalyticsPage::SORTS" :value="$this->sort" wire:model.live="sort" class="rg-admin-analytics__sort" />
        </x-slot:aside>
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-analytics__sources" role="table" aria-label="Traffic sources">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell rg-admin-analytics__source-cell" role="columnheader">Source · medium</div>
                    @foreach (['Visitors', 'Started', 'Completed', 'Registered', 'Activated'] as $column)
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                    @endforeach
                    <div class="rg-admin-table__cell" role="columnheader">Attribution basis</div>
                </div>
                @foreach ($data['sources']['rows'] as $row)
                    <div
                        @class(['rg-admin-table__row rg-admin-analytics__source-row', 'rg-admin-analytics__source-row--pickable' => $row['key'] !== null, 'rg-admin-analytics__source-row--selected' => $row['selected']])
                        role="row"
                        wire:key="analytics-source-row-{{ $row['key'] ?? 'other-'.$loop->index }}"
                        @if ($row['key'] !== null) wire:click="pickSource(@js($row['key']))" @endif
                    >
                        <div class="rg-admin-table__cell rg-admin-analytics__source-cell" role="cell">
                            <span class="rg-admin-analytics__source">
                                @if ($row['key'] !== null)
                                    <button type="button" class="rg-admin-analytics__source-name" aria-pressed="{{ $row['selected'] ? 'true' : 'false' }}">{{ $row['name'] }}</button>
                                @else
                                    <span class="rg-admin-analytics__source-name">{{ $row['name'] }}</span>
                                @endif
                                <span class="rg-admin-analytics__source-medium">{{ $row['medium'] }}</span>
                            </span>
                            <x-admin.ui.badge :tone="$row['tone']">{{ $row['group'] }}</x-admin.ui.badge>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__figure" role="cell"><span class="rg-admin-analytics__count">{{ $row['visitors'] }}</span></div>
                        @foreach ($row['cells'] as $index => [$count, $rate])
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__figure" role="cell">
                                <span>{{ $count }}</span>
                                <span @class(['rg-admin-analytics__rate', 'rg-admin-analytics__rate--due' => $index === 2 && $row['due']])>{{ $rate }}</span>
                            </div>
                        @endforeach
                        <div class="rg-admin-table__cell rg-admin-analytics__basis" role="cell">{{ $row['basis'] }}</div>
                    </div>
                @endforeach
                <div class="rg-admin-table__row rg-admin-analytics__sources-total" role="row">
                    <div class="rg-admin-table__cell rg-admin-analytics__source-cell" role="cell">All sources</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell"><span class="rg-admin-analytics__count">{{ $data['sources']['total']['visitors'] }}</span></div>
                    @foreach ($data['sources']['total']['cells'] as $cell)
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $cell }}</div>
                    @endforeach
                    <div class="rg-admin-table__cell rg-admin-analytics__basis rg-admin-analytics__basis--quiet" role="cell">{{ $data['sources']['total']['unattributed'] }}</div>
                </div>
            </div>
        </div>
        <x-slot:footer>A visitor who came from two sources counts in each, so the rows can add up to more than All sources. A rate shows “—” until the number it divides by reaches {{ $data['sources']['minimum'] }}.</x-slot:footer>
    </x-admin.ui.card>

    <div class="rg-admin-analytics__trio">
        <x-admin.ui.card title="Referral performance" description="Results shared from a game’s result page, and who came through them." data-testid="analytics-referral">
            <dl class="rg-admin-analytics__list">
                @foreach ($data['referral'] as $line)
                    <div class="rg-admin-analytics__line">
                        <dt class="rg-admin-analytics__line-label">{{ $line['label'] }}</dt>
                        <dd class="rg-admin-analytics__line-value">{{ $line['value'] }}</dd>
                        <dd class="rg-admin-analytics__line-rate">{{ $line['rate'] }}</dd>
                    </div>
                @endforeach
            </dl>
            <p class="rg-admin-analytics__foot">A share click doesn’t prove the link was posted. Visits from shared results count the people who arrived through one.</p>
        </x-admin.ui.card>

        <x-admin.ui.card title="Data notes" description="Why these numbers can differ from an ad network’s." data-testid="analytics-notes">
            <ul class="rg-admin-analytics__notes">
                @foreach ($data['notes'] as $note)
                    <li class="rg-admin-analytics__note">
                        <x-admin.ui.icon :name="$note['icon']" :size="15" class="rg-admin-analytics__note-icon" />
                        <span><span class="rg-admin-analytics__note-title">{{ $note['title'] }}.</span> {{ $note['text'] }}</span>
                    </li>
                @endforeach
            </ul>
        </x-admin.ui.card>

        <x-admin.ui.card title="Tracking" :description="'What '.site_name().' records itself. Nothing here comes from an external tool.'" data-testid="analytics-tracking">
            <ul class="rg-admin-analytics__list">
                @foreach ($data['tracking']['rows'] as $line)
                    <li class="rg-admin-analytics__line rg-admin-analytics__line--setting">
                        <span class="rg-admin-analytics__setting">
                            <span class="rg-admin-analytics__setting-name">{{ $line['name'] }}</span>
                            @if ($line['note'] !== null)
                                <span class="rg-admin-analytics__setting-note">{{ $line['note'] }}</span>
                            @endif
                        </span>
                        <x-admin.ui.badge :tone="$line['tone']" :dot="$line['dot']">{{ $line['state'] }}</x-admin.ui.badge>
                    </li>
                @endforeach
            </ul>
            <div class="rg-admin-analytics__actions">
                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$data['tracking']['url']">Tracking settings</x-admin.ui.button>
            </div>
        </x-admin.ui.card>
    </div>
@endif
