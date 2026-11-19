@php
    /*
     * Analytics → Retention (RetentionTab), as the reference draws it: what
     * a qualifying return is, the five tiles, the cohorts of the last nine
     * weeks — the cuts offered beside their title, a cell per window shaded
     * the darker the more came back, the legend, and the line adding the
     * cohorts up to the period's accounts when the reference draws it — then
     * the sources and the games side by side, each row a cut of the cohorts
     * on a click and every account again on a second, with what each table
     * says together. The tables scroll sideways inside their cards where the
     * cards are narrower. With no account at all, the tab says so once.
     */
@endphp

@if ($data['empty'])
    <x-admin.ui.card>
        <x-admin.ui.empty-state icon="repeat" title="No new accounts for these filters" data-testid="analytics-empty">
            No confirmed account signed up in {{ $data['range'] }} or in the nine weeks the cohorts cover. Pick another range or clear the filters.
            @if ($data['filtered'])
                <x-slot:action>
                    <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
                </x-slot:action>
            @endif
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    <x-admin.ui.inline-notice tone="info" icon="repeat" data-testid="analytics-retention-definition">
        <strong class="rg-admin-analytics__retention-lead">Retention counts a qualifying return:</strong> {{ $data['definition'] }}
    </x-admin.ui.inline-notice>

    <x-admin.ui.card flush clip data-testid="analytics-retention-cards">
        <dl class="rg-admin-analytics__kpis rg-admin-analytics__kpis--retention">
            @foreach ($data['kpis'] as $kpi)
                <div class="rg-admin-analytics__kpi" data-testid="analytics-retention-card">
                    <dt class="rg-admin-analytics__kpi-label">
                        {{ $kpi['label'] }}
                        <span class="rg-admin-analytics__shield" title="Counted by RateGuru"><x-admin.ui.icon name="shield-check" :size="12" /></span>
                    </dt>
                    <dd class="rg-admin-analytics__kpi-value">{{ $kpi['value'] }}</dd>
                    <dd class="rg-admin-analytics__kpi-unit">{{ $kpi['note'] }}</dd>
                </div>
            @endforeach
        </dl>
    </x-admin.ui.card>

    @php($cohorts = $data['cohorts'])
    <x-admin.ui.card :title="$cohorts['title']" :description="$cohorts['note']" flush class="rg-admin-analytics__retention-card" data-testid="analytics-retention-cohorts">
        <x-slot:aside>
            <div class="rg-admin-analytics__retention-chips" role="radiogroup" aria-label="Cohorts of">
                @foreach ($cohorts['chips'] as $chip)
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $chip['on'] ? 'true' : 'false' }}"
                        title="{{ $chip['label'] }}"
                        @class(['rg-admin-analytics__retention-chip', 'rg-admin-analytics__retention-chip--on' => $chip['on']])
                        data-testid="analytics-retention-chip"
                        wire:click="$set('dim', @js($chip['dim']))"
                    >{{ $chip['label'] }}</button>
                @endforeach
            </div>
        </x-slot:aside>
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-analytics__cohorts" role="table" aria-label="Registration cohorts">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">Registration week</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Users</div>
                    @foreach (['Day 1', 'Day 7', 'Day 30'] as $column)
                        <div class="rg-admin-table__cell rg-admin-analytics__cohort-head" role="columnheader">{{ $column }}</div>
                    @endforeach
                </div>
                @foreach ($cohorts['rows'] as $row)
                    <div class="rg-admin-table__row rg-admin-analytics__cohort-row" role="row" data-testid="analytics-retention-cohort" wire:key="analytics-retention-cohort-{{ $row['key'] }}">
                        <div class="rg-admin-table__cell rg-admin-analytics__cohort-week" role="cell">
                            <span class="rg-admin-analytics__cohort-label">{{ $row['label'] }}</span>
                            @if ($row['note'] !== null)
                                <span class="rg-admin-analytics__cohort-note">{{ $row['note'] }}</span>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__cohort-users" role="cell">{{ $row['users'] }}</div>
                        @foreach ($row['cells'] as $cell)
                            <div class="rg-admin-table__cell rg-admin-analytics__cohort-cell" role="cell">
                                <span
                                    @class([
                                        'rg-admin-analytics__heat',
                                        'rg-admin-analytics__heat--'.$cell['tone'],
                                        'rg-admin-analytics__heat--dark' => $cell['dark'],
                                    ])
                                    title="{{ $cell['title'] }}"
                                    @if ($cell['tone'] === 'heat') style="--rg-admin-analytics-heat: {{ $cell['heat'] }}%" @endif
                                >{{ $cell['value'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rg-admin-analytics__cohort-legend">
            <span><strong class="rg-admin-analytics__cohort-key">—</strong> Not matured: the cohort is younger than the window. It isn’t 0%.</span>
            <span><strong class="rg-admin-analytics__cohort-key">n &lt; {{ $cohorts['minimum'] }}</strong> Too few users to report a rate.</span>
        </div>
        @if ($cohorts['reconciliation'] !== null)
            <p class="rg-admin-analytics__cohort-recon" data-testid="analytics-retention-reconciliation">{{ $cohorts['reconciliation'] }}</p>
        @endif
    </x-admin.ui.card>

    <div class="rg-admin-analytics__retention-pair">
        @foreach ($data['tables'] as $table)
            <x-admin.ui.card :title="$table['title']" :description="$table['note']" flush data-testid="analytics-retention-{{ $table['key'] }}">
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid rg-admin-analytics__retention-table" role="table" aria-label="{{ $table['title'] }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">{{ $table['first'] }}</div>
                            @foreach (['Accounts', 'D1', 'D7', 'D30', 'Returned by D7'] as $column)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                            @endforeach
                        </div>
                        @foreach ($table['rows'] as $row)
                            <div
                                @class(['rg-admin-table__row rg-admin-analytics__retention-row', 'rg-admin-table__row--selected' => $row['chosen']])
                                role="row"
                                data-testid="analytics-retention-row"
                                data-dim="{{ $row['dim'] }}"
                                wire:key="analytics-retention-{{ $table['key'] }}-{{ $row['dim'] }}"
                                wire:click="$set('dim', @js($row['toggle']))"
                            >
                                <div class="rg-admin-table__cell rg-admin-analytics__retention-name-cell" role="cell">
                                    <button type="button" class="rg-admin-analytics__retention-name" aria-pressed="{{ $row['chosen'] ? 'true' : 'false' }}" title="{{ $row['name'] }}">{{ $row['name'] }}</button>
                                    <span class="rg-admin-analytics__retention-sub" title="{{ $row['sub'] }}">{{ $row['sub'] }}</span>
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__retention-figure" role="cell">{{ $row['accounts'] }}</div>
                                @foreach ($row['rates'] as $rate)
                                    <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__retention-figure', 'rg-admin-analytics__retention-figure--quiet' => $rate['quiet'], 'rg-admin-analytics__retention-figure--small' => $rate['small'], 'rg-admin-analytics__retention-figure--strong' => $rate['strong']]) role="cell" title="{{ $rate['title'] }}">{{ $rate['value'] }}</div>
                                @endforeach
                                <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__retention-kept', 'rg-admin-analytics__retention-figure--quiet' => $row['kept']['quiet'], 'rg-admin-analytics__retention-figure--small' => $row['kept']['small']]) role="cell" title="{{ $row['kept']['title'] }}">{{ $row['kept']['value'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="rg-admin-analytics__retention-answer" data-testid="analytics-retention-answer">{{ $table['answer'] }}</p>
            </x-admin.ui.card>
        @endforeach
    </div>
@endif
