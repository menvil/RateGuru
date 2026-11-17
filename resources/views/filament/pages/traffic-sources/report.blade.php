{{--
    Report: the period's tiles, mobile against desktop, how accounts came,
    then the tree — one level split by a dimension under the rows opened,
    its ten biggest groups and the rest in one row — and, split by site or
    zone, the networks' sites and zones with the block rule's verdict.
--}}
@php
    $tiles = [
        ['Visits', number_format($total->visits), 'opened '.$percent($total->openRate())],
        ['New visitors', number_format($total->newVisitors()), 'started '.$percent($total->startRate())],
        ['Accounts', number_format($total->accounts), $percent($total->accountRate()).' of new visitors'],
        ['Spend', $money($total->spend), $money($total->spendEstimated).' an estimate'],
        ['Value', $money($total->value()), $money($total->valueReal).' brought'],
        ['ROI', $total->roi() === null ? '—' : number_format($total->roi(), 0).'%', 'value against spend'],
        ['Cost of an account', $total->costPerAccount() === null ? '—' : $money($total->costPerAccount()), 'confirmed accounts only'],
    ];
    $label = function (array $keys) use ($labels, $dimension): string {
        $column = $dimension->columns()[0];
        $value = $keys[$column] ?? null;

        if ($value === null || $value === '') {
            return match ($column) {
                'source_id' => ucfirst((string) ($keys['channel'] ?? 'Unpaid')),
                'campaign_id' => 'No campaign',
                'game_id' => 'No game',
                'version_id' => 'No version',
                default => 'Unknown',
            };
        }

        return $labels[$column][(string) $value] ?? (string) $value;
    };
@endphp

<div class="rg-admin-traffic__tiles" data-testid="traffic-report-tiles">
    @foreach ($tiles as [$title, $value, $note])
        <div class="rg-admin-traffic__tile">
            <span class="rg-admin-traffic__tile-label">{{ $title }}</span>
            <span class="rg-admin-traffic__tile-value">{{ $value }}</span>
            <span class="rg-admin-traffic__tile-note">{{ $note }}</span>
        </div>
    @endforeach
</div>

<div class="rg-admin-traffic__pair">
    <x-admin.ui.card title="Mobile and desktop">
        <dl class="rg-admin-traffic__facts">
            @foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $device => $name)
                @php($row = $byDevice[$device] ?? new \App\Support\Traffic\Reports\ReportFigures)
                <div><dt>{{ $name }}</dt><dd>{{ number_format($row->visits) }} visits · {{ number_format($row->accounts) }} accounts · {{ $money($row->spend) }}</dd></div>
            @endforeach
        </dl>
    </x-admin.ui.card>
    <x-admin.ui.card title="How accounts come">
        <dl class="rg-admin-traffic__facts">
            <div><dt>Saw a sign-up form</dt><dd>{{ number_format($accountsPath['signup_form']) }}</dd></div>
            <div><dt>Signed up</dt><dd>{{ number_format($accountsPath['signup']) }} · {{ number_format($accountsPath['late']) }} after their click’s window</dd></div>
            <div><dt>Where</dt><dd>game {{ $accountsPath['place_game'] }} · post {{ $accountsPath['place_post'] }} · feed {{ $accountsPath['place_feed'] }} · elsewhere {{ $accountsPath['place_other'] }}</dd></div>
            <div><dt>How soon</dt><dd>{{ $accountsPath['within_10_minutes'] }} within 10 minutes · {{ $accountsPath['within_an_hour'] }} within an hour · {{ $accountsPath['within_a_day'] }} within a day</dd></div>
            <div><dt>Signed in instead</dt><dd>{{ number_format($accountsPath['login']) }}</dd></div>
        </dl>
    </x-admin.ui.card>
</div>

<div class="rg-admin-traffic__toolbar">
    <x-admin.ui.filter-dropdown label="Split by" id="rg-admin-traffic-by" :options="$dimensions" :value="$by" wire:model.live="by" />
    @if ($pathSteps !== [])
        <span class="rg-admin-traffic__path">Within {{ implode(' › ', $pathSteps) }}</span>
        <x-admin.ui.button size="sm" icon="x" wire:click="closePath">Show everything</x-admin.ui.button>
    @endif
</div>

@if ($level['total']->visits === 0)
    <x-admin.ui.empty-state icon="list" title="No visits in this period">Visits show here once a link carrying rg_src is clicked, or a page runs in a browser.</x-admin.ui.empty-state>
@else
    <div class="rg-admin-table__scroll">
        <div class="rg-admin-table__grid rg-admin-traffic__grid" role="table" aria-label="Report by {{ strtolower($dimension->label()) }}">
            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                @foreach ([$dimension->label(), 'Visits', 'Opened', 'Started', 'Accounts', 'Spend', 'Value', 'ROI', 'Cost of an account'] as $heading)
                    <div class="rg-admin-table__cell" role="columnheader">{{ $heading }}</div>
                @endforeach
            </div>
            @foreach ($level['shown'] as $group)
                @php($row = $group['figures'])
                <div class="rg-admin-table__row" role="row" wire:key="level-{{ md5(json_encode($group['keys'])) }}">
                    <div class="rg-admin-table__cell" role="cell">
                        <button type="button" class="rg-admin-traffic__drill" wire:click="drill('{{ $dimension->value }}', @js($group['keys'][$dimension->columns()[0]] ?? null))">{{ $label($group['keys']) }}</button>
                    </div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($row->visits) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $percent($row->openRate()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $percent($row->startRate()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($row->accounts) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row->spend) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($row->value()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row->roi() === null ? '—' : number_format($row->roi(), 0).'%' }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $row->costPerAccount() === null ? '—' : $money($row->costPerAccount()) }}</div>
                </div>
            @endforeach
            @if ($level['rest'] !== null)
                <div class="rg-admin-table__row" role="row">
                    <div class="rg-admin-table__cell" role="cell">+ {{ $level['restCount'] }} more</div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($level['rest']->visits) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $percent($level['rest']->openRate()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $percent($level['rest']->startRate()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ number_format($level['rest']->accounts) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($level['rest']->spend) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $money($level['rest']->value()) }}</div>
                    <div class="rg-admin-table__cell" role="cell">{{ $level['rest']->roi() === null ? '—' : number_format($level['rest']->roi(), 0).'%' }}</div>
                    <div class="rg-admin-table__cell" role="cell">—</div>
                </div>
            @endif
            <div class="rg-admin-table__row rg-admin-traffic__total" role="row">
                <div class="rg-admin-table__cell" role="cell">Total</div>
                <div class="rg-admin-table__cell" role="cell">{{ number_format($level['total']->visits) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $percent($level['total']->openRate()) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $percent($level['total']->startRate()) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ number_format($level['total']->accounts) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $money($level['total']->spend) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $money($level['total']->value()) }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $level['total']->roi() === null ? '—' : number_format($level['total']->roi(), 0).'%' }}</div>
                <div class="rg-admin-table__cell" role="cell">{{ $level['total']->costPerAccount() === null ? '—' : $money($level['total']->costPerAccount()) }}</div>
            </div>
        </div>
    </div>
    @if (\App\Support\Traffic\Money\TrafficMoney::of($unallocated)->isPositive())
        <p class="rg-admin-traffic__note">{{ $money($unallocated) }} of spend found no visit to share it over; it counts in the totals.</p>
    @endif
@endif

@if ($zones !== null)
    <x-admin.ui.card :title="$dimension->value === 'zone' ? 'Zones' : 'Sites'" description="The last block rule verdict for each, by this period's figures. Block by hand, or through the network's API where it can." data-testid="traffic-zones">
        @forelse ($zones as $zone)
            <div class="rg-admin-traffic__zone" wire:key="zone-{{ $zone['source_id'] }}-{{ $zone['ref'] }}">
                <span class="rg-admin-traffic__mono">{{ $zone['ref'] !== '' ? $zone['ref'] : '—' }}</span>
                <span>{{ $zone['source'] }}</span>
                <span>{{ number_format($zone['figures']->visits) }} clicks · {{ $percent($zone['figures']->openRate()) }} opened · {{ $money($zone['figures']->spend) }}</span>
                <x-admin.ui.badge :tone="match ($zone['judgement']['state']) { 'bots', 'losing' => 'danger', 'weak_start', 'watch' => 'warning', 'profit' => 'success', default => 'neutral' }">{{ str_replace('_', ' ', $zone['judgement']['state']) }}</x-admin.ui.badge>
                @if ($zone['block'] !== null)
                    <x-admin.ui.badge tone="outline">{{ str_replace('_', ' ', $zone['block']->network_state->value) }}</x-admin.ui.badge>
                    <x-admin.ui.button size="sm" wire:click="unblock({{ $zone['block']->id }})">Unblock</x-admin.ui.button>
                @elseif ($zone['ref'] !== '')
                    @if ($zone['api'])
                        <x-admin.ui.button size="sm" variant="danger" wire:click="block({{ $zone['source_id'] }}, '{{ $zone['level'] }}', @js($zone['ref']), true)">Block via API</x-admin.ui.button>
                    @endif
                    <x-admin.ui.button size="sm" wire:click="block({{ $zone['source_id'] }}, '{{ $zone['level'] }}', @js($zone['ref']), false)">Mark as blocked</x-admin.ui.button>
                @endif
            </div>
        @empty
            <p class="rg-admin-traffic__note">No network sites or zones in this period.</p>
        @endforelse
    </x-admin.ui.card>
@endif
