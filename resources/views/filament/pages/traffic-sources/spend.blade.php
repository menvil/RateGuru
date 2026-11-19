{{--
    Spend, as the reference draws it: the ways a source's spend is known and
    the sources using each; spend by source — how it is worked out, what
    reports count, what the network itself says and how the source stands —
    each opening its campaigns; then, for the source chosen, day by day: its
    clicks, the impressions the network billed and how many of them reached
    RateGuru, what their links said they cost, the network's own figure and
    what reports count, with a day corrected or the network's file taken.
--}}
@php
    $origins = ['api' => ['From the API', 'success'], 'csv' => ['From a file', 'success'], 'manual' => ['Typed in', 'outline'], 'fixed' => ['Fixed price', 'outline']];
@endphp

<section class="rg-admin-traffic__panel" data-testid="traffic-spend-methods">
    <header class="rg-admin-traffic__panel-head">
        <h2 class="rg-admin-traffic__panel-title">Where spend comes from</h2>
        <p class="rg-admin-traffic__note">Every source names one way to know what its visits cost. Value is always RateGuru’s own count; spend is the only figure that comes from outside.</p>
    </header>
    <div class="rg-admin-traffic__methods">
        @foreach ($methods as $method)
            <div @class(['rg-admin-traffic__method', 'rg-admin-traffic__method--unused' => $method['users'] === []])>
                <span class="rg-admin-traffic__method-name"><x-admin.ui.icon :name="$method['icon']" :size="13" />{{ $method['name'] }}</span>
                <span class="rg-admin-traffic__note">{{ $method['text'] }}</span>
                <strong class="rg-admin-traffic__method-users">{{ $method['users'] === [] ? 'Not used yet' : 'Used by '.implode(', ', $method['users']) }}</strong>
            </div>
        @endforeach
    </div>
</section>

<section class="rg-admin-traffic__panel" data-testid="traffic-spend-sources">
    <header class="rg-admin-traffic__panel-head">
        <h2 class="rg-admin-traffic__panel-title">Spend by source</h2>
        <p class="rg-admin-traffic__note">Spend in reports for the period, how it was worked out, and what the network itself reports where RateGuru can ask it. Spend no visit took still counts, here and in the reports.</p>
    </header>
    @if ($spendRows === [])
        <x-admin.ui.empty-state icon="megaphone" title="No paid sources yet">Add a network or a banner under Sources; its spend shows here.</x-admin.ui.empty-state>
    @else
        <div class="rg-admin-traffic__perf rg-admin-table__scroll">
            <div class="rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--spend" role="treegrid" aria-label="Spend by source">
                <div class="rg-admin-traffic__spend-row rg-admin-traffic__perf-row--head" role="row">
                    @foreach (['Source · campaign', 'How it’s worked out', 'Visits', 'In reports', 'Network says', 'Status', ''] as $heading)
                        <span role="columnheader">{{ $heading }}</span>
                    @endforeach
                </div>
                @foreach ($spendRows as $row)
                    <div class="rg-admin-traffic__spend-row rg-admin-traffic__perf-row--top" role="row" wire:key="spend-{{ $row['id'] }}" aria-expanded="{{ $row['open'] ? 'true' : 'false' }}">
                        <span role="gridcell" class="rg-admin-traffic__perf-name">
                            <button type="button" class="rg-admin-traffic__toggle" wire:click="toggleSpendSource({{ $row['id'] }})" aria-label="{{ $row['open'] ? 'Close' : 'Open' }} {{ $row['name'] }}"><x-admin.ui.icon :name="$row['open'] ? 'chevron-down' : 'chevron-right'" :size="14" /></button>
                            <span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">{{ $row['name'] }}</span><span class="rg-admin-traffic__note">{{ $row['kind'] }}</span></span>
                        </span>
                        <span role="gridcell" class="rg-admin-traffic__method-name"><x-admin.ui.icon :name="$row['how']['icon']" :size="12" />{{ $row['how']['text'] }}</span>
                        <span role="gridcell" class="rg-admin-traffic__fig"><strong>{{ number_format($row['visits']) }}</strong></span>
                        <span role="gridcell" class="rg-admin-traffic__fig">
                            <strong>{{ $money($row['reported']) }}</strong>
                            @if (\App\Support\Traffic\Money\TrafficMoney::of($row['estimated'])->isPositive())<small>incl. {{ $money($row['estimated']) }} estimated</small>@endif
                            @if (\App\Support\Traffic\Money\TrafficMoney::of($row['without'])->isPositive())<small>{{ $money($row['without']) }} without visits</small>@endif
                        </span>
                        <span role="gridcell" class="rg-admin-traffic__fig"><strong>{{ $row['network'][0] }}</strong>@if ($row['network'][1] !== null)<small>{{ $row['network'][1] }}</small>@endif</span>
                        <span role="gridcell" class="rg-admin-traffic__status"><span class="rg-admin-traffic__status-dot rg-admin-traffic__status-dot--{{ $row['status']['tone'] }}"></span>{{ $row['status']['text'] }}</span>
                        <span role="gridcell">
                            @if ($row['api'])
                                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="syncSource({{ $row['id'] }})">Sync now</x-admin.ui.button>
                            @else
                                <x-admin.ui.button size="sm" icon="file" wire:click="$set('spendSource', '{{ $row['id'] }}')" x-on:click="$nextTick(() => document.getElementById('rg-admin-spend-correct')?.scrollIntoView({ behavior: 'smooth' }))">Import CSV</x-admin.ui.button>
                            @endif
                        </span>
                    </div>
                    @if ($row['open'])
                        @forelse ($row['campaigns'] as $campaign)
                            <div class="rg-admin-traffic__spend-row" role="row">
                                <span role="gridcell" class="rg-admin-traffic__perf-name" style="--rg-admin-traffic-depth: 1"><span class="rg-admin-traffic__toggle-space"></span><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title rg-admin-traffic__mono">{{ $campaign['external'] }}</span><span class="rg-admin-traffic__note">{{ $campaign['name'] }}</span></span></span>
                                <span role="gridcell"></span>
                                <span role="gridcell" class="rg-admin-traffic__fig">{{ number_format($campaign['figures']->visits) }}</span>
                                <span role="gridcell" class="rg-admin-traffic__fig"><strong>{{ $money($campaign['figures']->spend) }}</strong>@if (\App\Support\Traffic\Money\TrafficMoney::of($campaign['figures']->spendEstimated)->isPositive())<small>incl. {{ $money($campaign['figures']->spendEstimated) }} est.</small>@endif</span>
                                <span role="gridcell" class="rg-admin-traffic__fig">{{ $campaign['network'] ?? '—' }}</span>
                                <span role="gridcell"></span>
                                <span role="gridcell"></span>
                            </div>
                        @empty
                            <div class="rg-admin-traffic__spend-row" role="row"><span role="gridcell" class="rg-admin-traffic__note" style="padding-left: 28px">No campaign spent anything in this period.</span></div>
                        @endforelse
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</section>

@if ($spendSourceName !== null)
    <section class="rg-admin-traffic__panel" data-testid="traffic-spend-days">
        <header class="rg-admin-traffic__perf-head">
            <div>
                <h2 class="rg-admin-traffic__panel-title">Day by day</h2>
                <p class="rg-admin-traffic__note">{{ $spendSourceName }}’s days, in its own time zone. Until a day closes its spend is an estimate from the cost in each click; then the network’s own figure, a file or a typed total replaces it. Impressions are what a network billing by the impression charged for; Reached, the visits of the same campaigns and sites that day.</p>
            </div>
            <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Source">
                @foreach ($spendSources as $id => $name)
                    <button type="button" role="radio" aria-checked="{{ (string) $spendSource === (string) $id ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => (string) $spendSource === (string) $id]) wire:click="$set('spendSource', '{{ $id }}')">{{ $name }}</button>
                @endforeach
            </div>
        </header>
        <div class="rg-admin-traffic__perf rg-admin-table__scroll">
            <div class="rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--days" role="table" aria-label="Day by day">
                <div class="rg-admin-traffic__day-row rg-admin-traffic__perf-row--head" role="row">
                    @foreach (['Day', 'Clicks', 'Impressions', 'Reached', 'From click costs', 'From the network', 'In reports'] as $heading)
                        <span role="columnheader">{{ $heading }}</span>
                    @endforeach
                </div>
                @forelse ($dayRows as $row)
                    <div class="rg-admin-traffic__day-row" role="row">
                        <span role="cell"><strong>{{ $row['day'] }}</strong></span>
                        <span role="cell" class="rg-admin-traffic__fig">{{ number_format($row['clicks']) }}</span>
                        <span role="cell" class="rg-admin-traffic__fig">{{ $row['reach'] === null ? '—' : number_format($row['reach']->impressions) }}</span>
                        <span role="cell" class="rg-admin-traffic__fig">
                            @if ($row['reach'] === null)
                                —
                            @else
                                <strong>{{ number_format($row['reach']->reached) }}</strong><small>{{ $percent($row['reach']->reachRate()) }}</small>
                            @endif
                        </span>
                        <span role="cell" @class(['rg-admin-traffic__fig', 'rg-admin-traffic__muted' => $row['origin'] !== null])>{{ $money($row['costs']) }}</span>
                        <span role="cell" class="rg-admin-traffic__fig">
                            @if ($row['network'] !== null)
                                <strong>{{ $money($row['network']) }}</strong>
                                @if ((float) $row['costs'] > 0)<small>{{ ((float) $row['network'] >= (float) $row['costs'] ? '+' : '−').number_format(abs((float) $row['network'] - (float) $row['costs']) / (float) $row['costs'] * 100, 1) }}% vs clicks</small>@endif
                            @else
                                <span class="rg-admin-traffic__note">{{ $row['networkOpen'] ? 'Not closed yet' : '—' }}</span>
                            @endif
                        </span>
                        <span role="cell" class="rg-admin-traffic__day-counted">
                            <x-admin.ui.badge :tone="$row['origin'] === null ? 'warning' : ($origins[$row['origin']][1] ?? 'outline')">{{ $row['origin'] === null ? 'Estimate' : ($origins[$row['origin']][0] ?? $row['origin']) }}</x-admin.ui.badge>
                            {{ $money($row['counted']) }}
                            @unless ($row['allocated'])<span class="rg-admin-traffic__note">sharing out</span>@endunless
                        </span>
                    </div>
                @empty
                    <div class="rg-admin-traffic__day-row" role="row"><span role="cell" class="rg-admin-traffic__note">No clicks and no figures in this period.</span></div>
                @endforelse
            </div>
        </div>
        <form class="rg-admin-traffic__correct" id="rg-admin-spend-correct" wire:submit="enterSpend">
            <span class="rg-admin-traffic__perf-label">
                <strong>Correct a day</strong>
                <span class="rg-admin-traffic__note">For a refund, a bonus or a day the network billed differently. What you type replaces every other figure for that day in {{ $spendSourceName }}; leave it empty to take a typed day back.</span>
            </span>
            <x-admin.ui.date-time-field date-only label="Day" id="rg-admin-spend-day" :value="$spendEntry['day']" :min="$correctDay['min']" :max="$correctDay['max']" :timezone="$correctDay['timezone']" placeholder="Choose a day" :quick-picks="['today', 'yesterday', 'week-ago']" wire:model="spendEntry.day" />
            <label class="rg-admin-field">
                <span class="rg-admin-label">Spend, USD</span>
                <span class="rg-admin-traffic__money-field"><span aria-hidden="true">$</span><input type="text" inputmode="decimal" class="rg-admin-input" placeholder="0.00" wire:model="spendEntry.amount" /></span>
            </label>
            <x-admin.ui.button size="sm" variant="primary" icon="check" type="submit">Use it</x-admin.ui.button>
            <label class="rg-admin-button rg-admin-button--secondary rg-admin-button--sm rg-admin-traffic__file">
                <x-admin.ui.icon name="file" :size="14" /> Import CSV
                {{-- Uploads under the source on screen as the file is chosen, so it is that source's file even if another is picked before it has uploaded. --}}
                <input type="file" accept=".csv,text/csv" wire:model="spendFiles.{{ $spendSource }}" wire:key="spend-file-{{ $spendSource }}" class="rg-admin-sr-only" />
            </label>
        </form>
        @foreach (['day', 'amount', 'file'] as $field)
            @error($field)<p class="rg-admin-games__note rg-admin-games__note--danger">{{ $message }}</p>@enderror
        @endforeach
    </section>
@endif
