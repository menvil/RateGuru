{{--
    Report, as the reference draws it: the period's tiles; mobile against
    desktop on paid traffic; how accounts come — the way to one, where they
    signed up, how long after the click; then Performance: a tree by source,
    device, country, game or version, each row opening the next level, ten
    rows a level and the rest in one; or Sites & zones, every network site or
    zone as one list, in the block rule's segments, searched, sorted, paged
    and ticked across pages to block, unblock or copy.
--}}
@php
    $paidValue = $paid->value();
    $paidProfit = $paidValue->minus(\App\Support\Traffic\Money\TrafficMoney::of($paid->spend));
    $signed = fn (\Brick\Math\BigDecimal $amount): string => ($amount->isNegative() ? '−' : '').$money($amount->abs());
    $share = fn (int $part, int $whole): string => $whole === 0 ? '—' : ($part * 100 / $whole >= 10 ? number_format($part * 100 / $whole, 0) : number_format($part * 100 / $whole, 1)).'%';
    $tiles = [
        ['Visits', number_format($total->visits), number_format($total->opened).' opened the page · '.$percent($total->openRate()), null],
        ['Started', number_format($total->started), $percent($total->startRate()).' of opened', null],
        ['New accounts', number_format($total->accounts), 'Confirmed · '.number_format($total->posted).' posted · '.number_format($total->knownOpened).' visitors already known', null],
        ['Spend', $money($total->spend), 'incl. '.$money($total->spendEstimated).' estimated, not synced yet', null],
        ['Value', $money($total->value()), $money($total->valueReal).' so far · '.$money($total->valueExpected).' expected', null],
        ['Profit', $signed($paidProfit), 'Paid traffic: value − spend', $paidProfit->isNegative() ? 'bad' : ($paidProfit->isPositive() ? 'good' : null)],
        ['ROI', $paid->roi() === null ? '—' : ($paid->roi() > 0 ? '+' : '').number_format($paid->roi(), 0).'%', 'Paid only · '.($paid->costPerAccount() === null ? '—' : $money($paid->costPerAccount())).' per account', ($paid->roi() ?? 0) < 0 ? 'bad' : (($paid->roi() ?? 0) > 0 ? 'good' : null)],
    ];
    $mobile = $byDevice['mobile'] ?? new \App\Support\Traffic\Reports\ReportFigures;
    $desktop = $byDevice['desktop'] ?? new \App\Support\Traffic\Reports\ReportFigures;
    $perVisit = fn (\App\Support\Traffic\Reports\ReportFigures $f, string $what): ?float => $f->visits === 0 ? null : (float) (string) ($what === 'cost' ? \App\Support\Traffic\Money\TrafficMoney::of($f->spend) : $f->value()) / $f->visits;
    $compare = function () use ($mobile, $desktop, $perVisit): ?string {
        $costM = $perVisit($mobile, 'cost');
        $costD = $perVisit($desktop, 'cost');
        $worthM = $perVisit($mobile, 'value');
        $worthD = $perVisit($desktop, 'value');

        if (! $costM || ! $costD || ! $worthM || ! $worthD) {
            return null;
        }

        $word = fn (float $ratio, string $more, string $less): string => number_format(abs($ratio - 1) * 100, 0).'% '.($ratio >= 1 ? $more : $less);

        return 'A desktop visit costs '.$word($costD / $costM, 'more', 'less').' and is worth '.$word($worthD / $worthM, 'more', 'less').'.';
    };
    $within = $accountsPath;
    $when = [
        ['Under 10 min', $within['within_10_minutes']],
        ['10–60 min', max(0, $within['within_an_hour'] - $within['within_10_minutes'])],
        ['1–24 hours', max(0, $within['within_a_day'] - $within['within_an_hour'])],
        ['Over a day', max(0, $paid->accounts - $within['within_a_day'])],
    ];
    $where = [
        ['In the game', $within['place_game'], 'Registration step or sign-up on Full Result'],
        ['On a post', $within['place_post'], 'Voting on the post the flow sent them to'],
        ['In the feed', $within['place_feed'], 'Voting or Sign up in the feed after the game'],
        ['Elsewhere on the site', $within['place_other'], 'Comments, profiles, other pages'],
    ];
    $columns = [
        'visits' => 'Visits', 'opened' => 'Opened', 'started' => 'Started', 'finished' => 'Finished', 'accounts' => 'Accounts', 'spend' => 'Spend',
        'value' => 'Value', 'profit' => 'Profit', 'roi' => 'ROI', 'cpv' => 'Cost / visit', 'vpv' => 'Value / visit', 'cpa' => 'Cost / account',
    ];
@endphp

<div class="rg-admin-traffic__tiles" data-testid="traffic-report-tiles">
    @foreach ($tiles as [$title, $value, $note, $tone])
        <div class="rg-admin-traffic__tile">
            <span class="rg-admin-traffic__tile-label">{{ $title }}</span>
            <span @class(['rg-admin-traffic__tile-value', 'rg-admin-traffic__good' => $tone === 'good', 'rg-admin-traffic__bad' => $tone === 'bad'])>{{ $value }}</span>
            <span class="rg-admin-traffic__tile-note">{{ $note }}</span>
        </div>
    @endforeach
</div>

<section class="rg-admin-traffic__panel" data-testid="traffic-devices">
    <header class="rg-admin-traffic__panel-head">
        <h2 class="rg-admin-traffic__panel-title">Mobile and desktop</h2>
        <p class="rg-admin-traffic__note">{{ $compare() ? $compare().' ' : '' }}Paid traffic only; click one to filter the page.</p>
    </header>
    <div class="rg-admin-traffic__devices">
        @foreach (['mobile' => ['Mobile', $mobile, 'smartphone'], 'desktop' => ['Desktop', $desktop, 'monitor']] as $key => [$name, $f, $icon])
            @php($paidVisits = $mobile->visits + $desktop->visits)
            <button type="button" @class(['rg-admin-traffic__device', 'rg-admin-traffic__device--on' => $device === $key]) wire:click="$set('device', '{{ $device === $key ? '' : $key }}')">
                <span class="rg-admin-traffic__device-head">
                    <span class="rg-admin-traffic__device-sign"><x-admin.ui.icon :name="$icon" :size="16" /></span>
                    <span class="rg-admin-traffic__device-name">{{ $name }}<span class="rg-admin-traffic__note">{{ $share($f->visits, $paidVisits) }} of visits · {{ number_format($f->visits) }}</span></span>
                    <span @class(['rg-admin-traffic__device-roi', 'rg-admin-traffic__good' => ($f->roi() ?? 0) > 0, 'rg-admin-traffic__bad' => ($f->roi() ?? 0) < 0])>{{ $f->roi() === null ? '—' : ($f->roi() > 0 ? '+' : '').number_format($f->roi(), 0).'%' }}</span>
                </span>
                <span class="rg-admin-traffic__device-stats">
                    <span><span class="rg-admin-traffic__note">Cost / visit</span>{{ $perVisit($f, 'cost') === null ? '—' : $money($perVisit($f, 'cost'), 4) }}</span>
                    <span><span class="rg-admin-traffic__note">Value / visit</span>{{ $perVisit($f, 'value') === null ? '—' : $money($perVisit($f, 'value'), 4) }}</span>
                    <span><span class="rg-admin-traffic__note">Finished</span>{{ $share($f->completed, $f->started) }}</span>
                    <span><span class="rg-admin-traffic__note">Cost / account</span>{{ $f->costPerAccount() === null ? '—' : $money($f->costPerAccount()) }}</span>
                </span>
            </button>
        @endforeach
    </div>
</section>

<section class="rg-admin-traffic__panel" data-testid="traffic-accounts">
    <header class="rg-admin-traffic__panel-head">
        <h2 class="rg-admin-traffic__panel-title">How accounts come</h2>
        <p class="rg-admin-traffic__note">Paid traffic only. A sign-up counts for the visitor’s latest paid click within the source’s window, on whatever page of the site it happens, and only once the account is confirmed.</p>
    </header>
    <div class="rg-admin-traffic__ways">
        <div class="rg-admin-traffic__way">
            <h3 class="rg-admin-traffic__way-title">The way to an account<span class="rg-admin-traffic__note">Of {{ number_format($paidNew) }} new visitors</span></h3>
            @foreach ([
                ['Saw a sign-up form', $within['signup_form'], $paidNew, 'Registration step, Full Result, or the form a vote or a comment opens', false],
                ['Signed up', $within['signup'], $within['signup_form'], 'Of those who saw a form · email, Google or Facebook', false],
                ['Confirmed', $paid->accounts, $within['signup'], 'Of those who signed up · these are the new accounts the reports and postbacks count', true],
            ] as [$name, $count, $of, $note, $good])
                @include('filament.pages.traffic-sources.bar', ['name' => $name, 'count' => $count, 'of' => $of, 'note' => $note, 'good' => $good])
            @endforeach
            <dl class="rg-admin-traffic__way-lines">
                <div><dt>Never confirmed: removed after 7 days, never counted</dt><dd>{{ number_format(max(0, $within['signup'] - $paid->accounts)) }}</dd></div>
                <div><dt>Signed in to an account they already had: not new, not counted</dt><dd>{{ number_format($within['login']) }}</dd></div>
                <div><dt>Signed up after the window closed: counted as Direct, no postback</dt><dd>{{ number_format($within['late']) }}</dd></div>
            </dl>
        </div>
        <div class="rg-admin-traffic__way">
            <h3 class="rg-admin-traffic__way-title">Where they signed up<span class="rg-admin-traffic__note">{{ number_format($paid->accounts) }} confirmed accounts</span></h3>
            @foreach ($where as [$name, $count, $note])
                @include('filament.pages.traffic-sources.bar', ['name' => $name, 'count' => $count, 'of' => $paid->accounts, 'note' => $note, 'good' => false])
            @endforeach
        </div>
        <div class="rg-admin-traffic__way">
            <h3 class="rg-admin-traffic__way-title">Time from the click to signing up<span class="rg-admin-traffic__note">The same {{ number_format($paid->accounts) }} accounts</span></h3>
            @foreach ($when as [$name, $count])
                @include('filament.pages.traffic-sources.bar', ['name' => $name, 'count' => $count, 'of' => $paid->accounts, 'note' => null, 'good' => false])
            @endforeach
            <p class="rg-admin-traffic__note">Most sign up right after the game. The rest come back later in the same browser: the click is kept with it, so their accounts still count for the click and its postback goes out when they confirm. Open an account in Logs → Milestones to see its whole way.</p>
        </div>
    </div>
</section>

<section class="rg-admin-traffic__panel" data-testid="traffic-performance">
    <header class="rg-admin-traffic__perf-head">
        <div>
            <h2 class="rg-admin-traffic__panel-title">Performance</h2>
            <p class="rg-admin-traffic__note">
                @if ($list !== null)
                    Every {{ $listBy === 'site' ? 'site' : 'zone' }} of the ad networks on these filters, across campaigns. Search, filter, sort by any column and tick the ones to block.
                @else
                    Click a row to open the next level; long levels show the top {{ 10 }} and open the rest as a list. Ticks appear on sites and zones of ad networks.
                @endif
            </p>
        </div>
        <div class="rg-admin-traffic__perf-tools">
            <div class="rg-admin-traffic__views" role="radiogroup" aria-label="View">
                @foreach ($views as $key => $name)
                    <button type="button" role="radio" aria-checked="{{ $reportView === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => $reportView === $key]) wire:click="$set('reportView', '{{ $key }}')">{{ $name }}</button>
                @endforeach
            </div>
            <x-admin.ui.button size="sm" icon="ban" wire:click="toggleBlockRule" data-testid="traffic-worth-blocking">Worth blocking · {{ $worthBlocking }}</x-admin.ui.button>
        </div>
    </header>

    @if ($blockRule)
        <form class="rg-admin-traffic__rule" wire:submit="saveSettings" data-testid="traffic-block-rule">
            <h3 class="rg-admin-traffic__way-title">Worth blocking<span class="rg-admin-traffic__note">{{ $worthBlocking }} sites and zones meet the rule on these filters, over the last 30 days of their own</span></h3>
            <p class="rg-admin-traffic__rule-line"><strong>Likely bots</strong>: at least <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" min="1" wire:model="settings.block_bot_clicks" aria-label="Clicks before bots show" /> clicks and fewer than <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" min="1" max="100" wire:model="settings.block_open_below" aria-label="Opened below, percent" />% opened the page.</p>
            <p class="rg-admin-traffic__rule-line"><strong>Losing</strong>: at least $<input class="rg-admin-input rg-admin-traffic__rule-input" type="text" inputmode="decimal" wire:model="settings.block_min_spend" aria-label="Spend before the money is judged" /> spent or <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" min="1" wire:model="settings.block_min_clicks" aria-label="Clicks before the money is judged" /> clicks, and ROI below <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" max="0" wire:model="settings.block_roi_below" aria-label="ROI below, percent" />% — judged once value is measured.</p>
            <p class="rg-admin-traffic__rule-line"><strong>Weak start</strong>: at least <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" min="1" wire:model="settings.block_start_clicks" aria-label="Clicks before a weak start shows" /> clicks and the game started below <input class="rg-admin-input rg-admin-traffic__rule-input" type="number" min="1" max="100" wire:model="settings.block_start_percent" aria-label="Start below, percent of the source" />% as often as on the source’s other places. Shown, never blocked by itself.</p>
            <div class="rg-admin-traffic__rule-actions">
                <x-admin.ui.button size="sm" variant="primary" type="submit">Save the rule</x-admin.ui.button>
                <x-admin.ui.button size="sm" wire:click="showWorthBlocking" trailing-icon="arrow-right">Show them</x-admin.ui.button>
                <x-admin.ui.button size="sm" variant="ghost" wire:click="toggleBlockRule">Close</x-admin.ui.button>
            </div>
        </form>
    @endif

    @if ($list === null)
        <p class="rg-admin-traffic__chain">Group by <strong>{{ implode(' › ', $chain) }}</strong></p>
        @if ($total->visits === 0)
            <x-admin.ui.empty-state icon="list" title="No visits in this period">Visits show here once a link carrying rg_src is clicked, or a page runs in a browser.</x-admin.ui.empty-state>
        @else
            <div class="rg-admin-traffic__perf rg-admin-table__scroll">
                <div class="rg-admin-traffic__perf-grid" role="treegrid" aria-label="Performance by {{ strtolower($views[$reportView]) }}">
                    <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--head" role="row">
                        <span role="columnheader">{{ implode(' · ', $chain) }}</span>
                        @foreach ($columns as $heading)
                            <span role="columnheader">{{ $heading }}</span>
                        @endforeach
                    </div>
                    @foreach ($tree as $row)
                        @if ($row['kind'] === 'more')
                            <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--more" role="row" wire:key="more-{{ md5($row['key']) }}">
                                <span class="rg-admin-traffic__perf-name" role="gridcell" style="--rg-depth: {{ $row['depth'] }}">
                                    @if ($row['list'] !== null)
                                        <button type="button" class="rg-admin-traffic__link" wire:click="listSites({{ $row['list']['source'] }}, '{{ $row['list']['level'] }}')">+ {{ number_format($row['count']) }} more {{ $row['noun'] }} · open as a list</button>
                                    @else
                                        <button type="button" class="rg-admin-traffic__link" wire:click="showMore(@js($row['key']))">+ {{ number_format($row['count']) }} more {{ $row['noun'] }}</button>
                                    @endif
                                </span>
                                @include('filament.pages.traffic-sources.figure-cells', ['f' => $row['figures']])
                            </div>
                        @else
                            <div @class(['rg-admin-traffic__perf-row', 'rg-admin-traffic__perf-row--top' => $row['depth'] === 0]) role="row" aria-level="{{ $row['depth'] + 1 }}" @if ($row['children']) aria-expanded="{{ $row['open'] ? 'true' : 'false' }}" @endif wire:key="node-{{ md5($row['key']) }}">
                                <span class="rg-admin-traffic__perf-name" role="gridcell" style="--rg-depth: {{ $row['depth'] }}">
                                    @if ($row['children'])
                                        <button type="button" class="rg-admin-traffic__toggle" wire:click="toggleNode(@js($row['key']))" aria-label="{{ $row['open'] ? 'Close' : 'Open' }} {{ $row['label'] }}">
                                            <x-admin.ui.icon :name="$row['open'] ? 'chevron-down' : 'chevron-right'" :size="14" />
                                        </button>
                                    @else
                                        <span class="rg-admin-traffic__toggle-space" aria-hidden="true"></span>
                                    @endif
                                    <span class="rg-admin-traffic__perf-label">
                                        <span @class(['rg-admin-traffic__perf-title', 'rg-admin-traffic__mono' => $row['mono']])>{{ $row['label'] }}</span>
                                        @if ($row['sub'])
                                            <span class="rg-admin-traffic__note">{{ $row['sub'] }}</span>
                                        @endif
                                    </span>
                                    @if ($row['flag'] !== null)
                                        <x-admin.ui.badge :tone="$row['flag']['tone']" :title="$row['flag']['tip']">{{ $row['flag']['label'] }}</x-admin.ui.badge>
                                    @endif
                                </span>
                                @include('filament.pages.traffic-sources.figure-cells', ['f' => $row['figures']])
                            </div>
                        @endif
                    @endforeach
                    <div class="rg-admin-traffic__perf-row rg-admin-traffic__perf-row--total" role="row">
                        <span class="rg-admin-traffic__perf-name" role="gridcell"><span class="rg-admin-traffic__perf-label"><span class="rg-admin-traffic__perf-title">Total</span><span class="rg-admin-traffic__note">These filters</span></span></span>
                        @include('filament.pages.traffic-sources.figure-cells', ['f' => $total])
                    </div>
                </div>
            </div>
            @if (\App\Support\Traffic\Money\TrafficMoney::of($unallocated)->isPositive())
                <p class="rg-admin-traffic__note">{{ $money($unallocated) }} of spend found no visit to share it over; it counts in the totals.</p>
            @endif
        @endif
    @else
        @include('filament.pages.traffic-sources.sites')
    @endif

    <footer class="rg-admin-traffic__perf-foot">
        <p class="rg-admin-traffic__note">Spend: from each network’s API, the cost in each click or the price set for a banner; unpaid traffic costs nothing. Value: confirmed accounts and what they did next, at the outcome prices on the Value tab; visits from the last 7 days add what the steps they reached are expected to bring, until their real outcome arrives.</p>
        <x-admin.ui.button size="sm" wire:click="showTab('spend')" trailing-icon="arrow-right">Where spend comes from</x-admin.ui.button>
    </footer>
</section>
