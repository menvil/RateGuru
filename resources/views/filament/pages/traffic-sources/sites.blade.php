{{--
    Sites & zones: every network site or zone on the filters as one list — a
    row per zone, or per site where a network has no zones, or per site, a
    place the network billed that sent no visit among them —
    found by its ID, network or campaign, leaving out those under so many
    clicks but for Low reach; split into the block rule's segments with their counts; what the
    list says at a glance; the impressions its network billed for it and the
    share that reached RateGuru, where the network reports it; sorted by any
    column and paged; ticked across pages to block, unblock or copy the IDs
    of.
--}}
@php
    $segments = [
        'all' => ['All', 'var(--rg-admin-text-tertiary)', ''],
        'block' => ['Worth blocking', 'var(--rg-admin-status-danger-fg)', 'Likely bots, losing, a weak start, or paid impressions that mostly never reach RateGuru: see the rule under Worth blocking'],
        'profit' => ['Profitable', 'var(--rg-admin-status-success-fg)', 'Enough data and value above spend'],
        'watch' => ['Below the line', 'var(--rg-admin-status-warning-dot)', 'Enough data, losing a little, not yet bad enough to block — or losing, but still within the days the rule waits after a first click'],
        'early' => ['Too early', 'var(--rg-admin-border-strong)', 'Not enough spend or clicks to judge the money; bots and a weak start still show'],
        'blocked' => ['Blocked', 'var(--rg-admin-text-secondary)', 'Blocked already; tick them to unblock'],
    ];
    $sorts = [
        'impressions' => 'Paid impr.', 'reach' => 'Reached',
        'visits' => 'Visits', 'opened' => 'Opened', 'started' => 'Started', 'finished' => 'Finished', 'accounts' => 'Accounts', 'spend' => 'Spend',
        'value' => 'Value', 'profit' => 'Profit', 'roi' => 'ROI', 'cpv' => 'Cost / visit', 'vpv' => 'Value / visit', 'cpa' => 'Cost / account',
    ];
    $pageTicked = $list['selectable'] !== [] && array_diff($list['selectable'], $selected) === [];
    $noun = $list['noun'];
@endphp

<div class="rg-admin-traffic__list-tools">
    <label class="rg-admin-traffic__list-control">
        <span class="rg-admin-traffic__note">One row per</span>
        <select class="rg-admin-traffic__select" wire:model.live="listBy" aria-label="One row per">
            <option value="zone">Zone (site when a network has no zones)</option>
            <option value="site">Site</option>
        </select>
    </label>
    <div class="rg-admin-traffic__list-search">
        <x-admin.ui.icon name="search" :size="14" />
        <input type="search" class="rg-admin-input" placeholder="Find a zone, site or campaign ID" wire:model.live.debounce.400ms="q" aria-label="Find a zone, site or campaign ID" />
    </div>
    <label class="rg-admin-traffic__list-control" title="Low reach rows show whatever their clicks: their paid impressions judge them">
        <span class="rg-admin-traffic__note">Hide under</span>
        <select class="rg-admin-traffic__select" wire:model.live="minClicks" aria-label="Hide those under so many clicks">
            @foreach ([0 => 'no limit', 50 => '50 clicks', 100 => '100 clicks', 200 => '200 clicks', 500 => '500 clicks', 1000 => '1,000 clicks'] as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </label>
</div>

<div class="rg-admin-traffic__segments" role="radiogroup" aria-label="Segment">
    @foreach ($segments as $key => [$name, $dot, $tip])
        <button type="button" role="radio" aria-checked="{{ $segment === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__segment', 'rg-admin-traffic__segment--on' => $segment === $key]) @if ($tip !== '') title="{{ $tip }}" @endif wire:click="$set('segment', '{{ $key }}')" data-testid="traffic-segment-{{ $key }}">
            <span class="rg-admin-traffic__segment-dot" style="background: {{ $dot }}"></span>{{ $name }}<span class="rg-admin-traffic__segment-count">{{ number_format($list['counts'][$key] ?? 0) }}</span>
        </button>
    @endforeach
</div>

@if ($list['insight'] !== null)
    <p class="rg-admin-traffic__insight"><x-admin.ui.icon name="chart-no-axes-column" :size="14" />{{ $list['insight'] }}</p>
@endif

@if ($list['selected'] > 0)
    <div class="rg-admin-traffic__selection" data-testid="traffic-selection" x-data="{ copied: false }">
        <strong>{{ number_format($list['selected']) }} selected</strong>
        @if (count($list['matching']) > $list['selected'] && $segment !== 'all')
            <button type="button" class="rg-admin-traffic__text-button" wire:click="selectMatching">Select all {{ number_format(count($list['matching'])) }} in {{ strtolower($segments[$segment][0]) }}</button>
        @elseif (count($list['matching']) > $list['selected'])
            <button type="button" class="rg-admin-traffic__text-button" wire:click="selectMatching">Select all {{ number_format(count($list['matching'])) }} matching</button>
        @endif
        <span class="rg-admin-traffic__selection-actions">
            @if ($segment === 'blocked')
                <x-admin.ui.button size="sm" wire:click="unblockSelected">Unblock</x-admin.ui.button>
                {{-- To every campaign each is for, through the network's API: one on some lists only, refused, taken off in the panel, or still sending. --}}
                <x-admin.ui.button size="sm" variant="ghost" icon="refresh-cw" wire:click="pushSelected" title="Send them to the network’s lists again, to every campaign">Push again</x-admin.ui.button>
            @else
                <x-admin.ui.button size="sm" variant="danger" icon="ban" wire:click="blockSelected">Block {{ number_format($list['selected']) }}</x-admin.ui.button>
            @endif
            {{-- Says Copied only once the clipboard took the IDs: the write can be refused, and there is no clipboard outside a secure page. --}}
            <x-admin.ui.button size="sm" icon="copy" :data-copy="$list['copyIds']" x-on:click="async () => { try { await navigator.clipboard.writeText($el.dataset.copy); copied = true; setTimeout(() => copied = false, 1500) } catch { $dispatch('rg-admin-toast', { message: 'The browser refused the clipboard, so nothing was copied.', tone: 'error' }) } }"><span x-text="copied ? 'Copied' : 'Copy IDs'">Copy IDs</span></x-admin.ui.button>
            <x-admin.ui.button size="sm" variant="ghost" wire:click="clearSelection">Clear</x-admin.ui.button>
        </span>
    </div>
@endif

@if ($list['matching'] === [])
    <x-admin.ui.empty-state icon="search-x" title="No {{ $noun }} here">
        {{ ($list['counts']['all'] ?? 0) === 0 ? 'No ad network sent a click with a '.($listBy === 'site' ? 'site' : 'zone or site').' ID on these filters.' : 'None match this segment and search.' }}
    </x-admin.ui.empty-state>
@else
    <div class="rg-admin-traffic__perf rg-admin-table__scroll">
        <div class="rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--list" role="grid" aria-label="Sites and zones">
            <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--head" role="row">
                <span class="rg-admin-traffic__perf-name" role="columnheader">
                    <input type="checkbox" class="rg-admin-traffic__tick" aria-label="Tick every row of this page" @checked($pageTicked) @disabled($list['selectable'] === []) wire:click="togglePage" />
                    {{ $listBy === 'site' ? 'Site · campaigns' : 'Zone · site · campaigns' }}
                </span>
                @foreach ($sorts as $key => $heading)
                    <span role="columnheader" aria-sort="{{ $sort === $key ? ($dir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                        <button type="button" @class(['rg-admin-traffic__sort', 'rg-admin-traffic__sort--on' => $sort === $key]) wire:click="sortBy('{{ $key }}')">{{ $heading }}@if ($sort === $key) {{ $dir === 'asc' ? '↑' : '↓' }}@endif</button>
                    </span>
                @endforeach
            </div>
            @foreach ($list['rows'] as $row)
                @php($tickable = $segment === 'blocked' ? $row['blocked'] : ! $row['blocked'])
                <div @class(['rg-admin-traffic__perf-row', 'rg-admin-traffic__perf-row--blocked' => $row['blocked'], 'rg-admin-traffic__perf-row--ticked' => in_array($row['key'], $selected, true)]) role="row" wire:key="site-{{ md5($row['key']) }}" data-testid="traffic-site-row">
                    <span class="rg-admin-traffic__perf-name" role="gridcell">
                        <input type="checkbox" class="rg-admin-traffic__tick" aria-label="Tick {{ $row['level'] }} {{ $row['ref'] }}" @checked(in_array($row['key'], $selected, true)) @disabled(! $tickable) wire:click="toggleSelected(@js($row['key']))" />
                        <span class="rg-admin-traffic__perf-label">
                            <span class="rg-admin-traffic__perf-title rg-admin-traffic__mono">{{ $row['level'] }} {{ $row['ref'] }}</span>
                            <span class="rg-admin-traffic__note">{{ implode(' · ', array_filter([$row['site'] !== null ? 'site '.$row['site'] : null, $row['source'], $row['campaign'], $row['early']])) }}</span>
                        </span>
                        @if ($row['flag'] !== null)
                            <x-admin.ui.badge :tone="$row['flag']['tone']" :title="$row['flag']['tip']">{{ $row['flag']['label'] }}</x-admin.ui.badge>
                        @endif
                    </span>
                    @include('filament.pages.traffic-sources.reach-cells', ['r' => $row['reach']])
                    @include('filament.pages.traffic-sources.figure-cells', ['f' => $row['figures']])
                </div>
            @endforeach
            <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--total" role="row">
                <span class="rg-admin-traffic__perf-name" role="gridcell"><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">Total · {{ number_format(count($list['matching'])) }} {{ $noun }}</span><span class="rg-admin-traffic__note">{{ $segment === 'all' ? 'Every page' : 'Every page of '.strtolower($segments[$segment][0]) }}</span></span></span>
                @include('filament.pages.traffic-sources.reach-cells', ['r' => $list['reach']])
                @include('filament.pages.traffic-sources.figure-cells', ['f' => $list['total']])
            </div>
        </div>
    </div>
    <div class="rg-admin-traffic__pager">
        <span class="rg-admin-traffic__note">{{ number_format($list['from']) }}–{{ number_format($list['to']) }} of {{ number_format(count($list['matching'])) }}</span>
        <span class="rg-admin-traffic__pager-buttons">
            <x-admin.ui.icon-button icon="chevron-left" label="Previous page" size="sm" :disabled="$list['page'] <= 1" wire:click="goToPage({{ $list['page'] - 1 }})" />
            <x-admin.ui.icon-button icon="chevron-right" label="Next page" size="sm" :disabled="$list['page'] >= $list['pages']" wire:click="goToPage({{ $list['page'] + 1 }})" />
        </span>
    </div>
@endif
