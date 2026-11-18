@php
    /*
     * A game's Analytics tab (GameAnalytics), as the reference draws it: the
     * filter bar — the period picker Traffic sources shares, the versions,
     * the audiences with their visitors, the device and the country, the days
     * compared and Step prices — then one audience over that period, for
     * every version or one, from every device and country or one, against
     * the window before — the figures and what the visits were worth, the
     * versions on paid traffic compared, the visitors by where they came
     * from, the funnel, who signed up and whether they confirmed — of
     * anonymous visitors, from the visits Traffic sources recorded, so never
     * confirmed ones still count once removed — the rounds reached and the
     * results earned in one version, the figures broken down by version,
     * flow, content set, device, country or group, and the traffic periods —
     * of one version, those it had a share in, with its own figures — each
     * with whether its versions can be compared yet and the way to its
     * results in Growth → Experiments.
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        <div class="rg-admin-games__analytics-filters" data-testid="game-analytics-filters">
            @include('filament.pages.elements.period-picker', ['picker' => $periodPicker, 'id' => 'rg-admin-games-analytics-period'])
            @if (! $noVersions)
                <x-admin.ui.filter-dropdown label="Versions" id="rg-admin-games-analytics-version" :options="$versionOptions" :value="(string) $version" wire:model.live="version" />
            @endif
            {{-- The audiences with their visitors over the period, on the segmented control's track. --}}
            <div class="rg-admin-segmented rg-admin-segmented--compact rg-admin-games__audiences" role="radiogroup" aria-label="Group">
                @foreach ($audiences as $key => $label)
                    <button type="button" role="radio" class="rg-admin-segmented__option" aria-checked="{{ $audience === $key ? 'true' : 'false' }}" wire:click="$set('audience', @js($key))">
                        <span>{{ $label }}</span>
                        <span class="rg-admin-games__audience-count">{{ $audienceCounts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            <x-admin.ui.filter-dropdown label="Device" id="rg-admin-games-analytics-device" :options="$deviceOptions" :value="$device" wire:model.live="device" />
            <x-admin.ui.filter-dropdown label="Country" id="rg-admin-games-analytics-country" class="rg-admin-games__country" :options="$countryOptions" :value="$country" wire:model.live="country" />
            {{-- Pushes the dates and Step prices right while the bar is one line; wrapped, they start a line of their own on the left, as the reference has. --}}
            <span class="rg-admin-games__analytics-spacer" aria-hidden="true"></span>
            <div class="rg-admin-games__analytics-scope">
                <span class="rg-admin-games__note" role="status">{{ $datesText }}</span>
                <x-admin.ui.button size="sm" icon="circle-dollar-sign" :href="$valueUrl">Step prices</x-admin.ui.button>
            </div>
        </div>
        @if ($visitNote !== null)
            <p class="rg-admin-games__note rg-admin-games__visit-note" data-testid="game-analytics-visit-note"><x-admin.ui.icon name="info" :size="14" />{{ $visitNote }}</p>
        @endif

        @if ($noVersions)
            <x-admin.ui.card>
                <x-admin.ui.empty-state icon="chart-no-axes-column" title="Nothing to measure yet">
                    Figures start with the first visitor of a published version.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" :href="$versionsUrl">Go to Versions</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            </x-admin.ui.card>
        @else
            @if ($noData)
                <x-admin.ui.card>
                    <x-admin.ui.empty-state icon="chart-no-axes-column" title="No visitors here yet">
                        Nobody of this group opened {{ $version > 0 ? 'this version' : 'the game' }}{{ $visitNote !== null ? ' on a recorded visit from this device or country' : '' }} in this period. Try a longer period or the other group{{ $visitNote !== null ? ', or set Device and Country back to All' : '' }}.
                    </x-admin.ui.empty-state>
                </x-admin.ui.card>
            @else
                <x-admin.ui.card>
                    <dl class="rg-admin-games__kpis" data-testid="game-analytics-kpis">
                        @foreach ($kpis as $kpi)
                            <div class="rg-admin-games__kpi">
                                <dt class="rg-admin-stat__label">{{ $kpi['label'] }}</dt>
                                <dd class="rg-admin-stat__value">{{ $kpi['value'] }}</dd>
                                <dd class="rg-admin-games__note">{{ $kpi['note'] }}</dd>
                                @if (($kpi['change']['text'] ?? '') !== '')
                                    <dd @class(['rg-admin-games__note rg-admin-games__change', 'rg-admin-games__change--good' => $kpi['change']['tone'] === 'good', 'rg-admin-games__change--bad' => $kpi['change']['tone'] === 'bad'])>{{ $kpi['change']['text'] }}</dd>
                                @endif
                            </div>
                        @endforeach
                    </dl>
                </x-admin.ui.card>
            @endif

            @if ($paid !== null)
                <x-admin.ui.card title="Versions on paid traffic" :description="$paid['note']" data-testid="game-paid-versions">
                    <x-slot:aside>
                        <div class="rg-admin-games__card-tools">
                            {{-- A menu rather than a row of chips: twenty sources and more stay within reach. --}}
                            <x-admin.ui.filter-dropdown label="Source" id="rg-admin-games-paid-source" class="rg-admin-games__paid-source" :options="$paid['sources']" :value="$paid['source']" wire:model.live="paidSource" />
                            <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$paid['reportUrl']">Open report</x-admin.ui.button>
                        </div>
                    </x-slot:aside>
                    @if ($paid['comparison'] !== null)
                        @php($c = $paid['comparison'])
                        <div @class(['rg-admin-games__verdict', 'rg-admin-games__verdict--leader' => $c['verdict'] === 'leader']) data-testid="game-version-comparison">
                            <x-admin.ui.icon :name="$c['verdict'] === 'leader' ? 'trophy' : 'activity'" :size="16" />
                            <div class="rg-admin-games__verdict-text">
                                <strong>{{ match ($c['verdict']) {
                                    'leader' => $c['a'].' brings more accounts',
                                    'ahead' => $c['a'].' is ahead, not sure yet',
                                    'too_early' => 'Too early to call',
                                    default => 'No clear difference yet',
                                } }}</strong>
                                <span>{{ $c['rateA'] }} of new visitors end up a confirmed account on {{ $c['a'] }}, against {{ $c['rateB'] }} on {{ $c['b'] }}.
                                    {{ match ($c['verdict']) {
                                        'leader' => 'Sure enough ('.number_format($c['confidence'] * 100, 0).'%): a gap this size is unlikely to be chance.',
                                        'ahead' => number_format($c['confidence'] * 100, 0).'% sure; about '.number_format((int) $c['needed']).' new visitors on each would tell.',
                                        'too_early' => 'Fewer than 10 accounts on one of them: let more visitors through before reading anything into it.',
                                        default => 'The gap is small enough to be chance.',
                                    } }}</span>
                                <span class="rg-admin-games__note">{{ $c['earlier'] }}</span>
                            </div>
                            <x-admin.ui.button size="sm" :href="$trafficUrl">Change shares</x-admin.ui.button>
                        </div>
                    @endif
                    @if ($paid['rows'] === [])
                        <p class="rg-admin-games__note">No paid visitor reached a version in this period.</p>
                    @else
                        <div class="rg-admin-table__scroll">
                            <div class="rg-admin-table__grid rg-admin-games__paid-versions" role="table" aria-label="Versions on paid traffic">
                                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                                    @foreach (['Version', 'Share', 'New visitors', 'Finished', 'Flow goal', 'Accounts', 'Cost / account', 'Value / visitor', 'ROI'] as $index => $column)
                                        <div @class(['rg-admin-table__cell', 'rg-admin-table__cell--numeric' => $index > 0]) role="columnheader">{{ $column }}</div>
                                    @endforeach
                                </div>
                                @foreach ($paid['rows'] as $row)
                                    <div class="rg-admin-table__row" role="row">
                                        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                            <div class="rg-admin-table__primary">{{ $row['name'] }} @if ($row['ahead'])<x-admin.ui.badge tone="success">Ahead</x-admin.ui.badge>@endif</div>
                                            <div class="rg-admin-table__meta">{{ $row['sub'] }}</div>
                                        </div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['share'] }}<span class="rg-admin-table__meta">{{ $row['shareNote'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['newVisitors'] }}<span class="rg-admin-table__meta">{{ $row['clicks'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['finished'] }}<span class="rg-admin-table__meta">{{ $row['finishedCount'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['goal'] }}<span class="rg-admin-table__meta">{{ $row['goalCount'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell"><strong @class(['rg-admin-games__change--good' => $row['ahead']])>{{ $row['accounts'] }}</strong><span class="rg-admin-table__meta">{{ $row['accountRate'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['costPerAccount'] }}<span class="rg-admin-table__meta">{{ $row['spent'] }}</span></div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['perVisitor'] }}</div>
                                        <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric', 'rg-admin-games__change--good' => $row['roiTone'] === 'good', 'rg-admin-games__change--bad' => $row['roiTone'] === 'bad']) role="cell">{{ $row['roi'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <p class="rg-admin-games__note rg-admin-games__card-foot">Every version gets the same networks, zones, devices and countries, because the version is picked after the click; a visitor keeps theirs when they come back. Step worth and postbacks stay one for the whole game: versions come and go, and splitting them would thin the data. The comparison uses what visitors did.</p>
                </x-admin.ui.card>
            @endif

            @if ($sources !== null)
                <x-admin.ui.card title="Traffic sources" :description="$sources['note']" data-testid="game-traffic-sources">
                    <x-slot:aside>
                        <div class="rg-admin-games__card-tools">
                            {{-- Its own device only while the page is of every device; with one chosen above, the card follows it. --}}
                            @if ($sourcesSwitch)
                                <x-admin.ui.segmented label="Device" compact :options="['all' => 'All devices', 'mobile' => 'Mobile', 'desktop' => 'Desktop']" :value="$cardDevice" wire:model.live="sourcesDevice" data-testid="game-traffic-sources-device" />
                            @endif
                            <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$sources['reportUrl']">Open report</x-admin.ui.button>
                        </div>
                    </x-slot:aside>
                    <div class="rg-admin-table__scroll">
                        <div class="rg-admin-table__grid rg-admin-games__sources" role="table" aria-label="Traffic sources">
                            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                                @foreach (['Source', 'Visitors', 'Started', 'Finished', 'Goal', 'Value / visitor', 'Cost / visitor', 'ROI'] as $index => $column)
                                    <div @class(['rg-admin-table__cell', 'rg-admin-table__cell--numeric' => $index > 0]) role="columnheader">{{ $column }}</div>
                                @endforeach
                            </div>
                            @foreach ([...$sources['groups'], ['total' => true, ...$sources['total']]] as $group)
                                @foreach ([$group, ...($group['children'] ?? [])] as $row)
                                    @php($top = $loop->first)
                                    <div @class(['rg-admin-table__row', 'rg-admin-games__sources-group' => $top && ! isset($group['total']), 'rg-admin-games__sources-total' => isset($group['total']), 'rg-admin-games__sources-child' => ! $top]) role="row">
                                        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                            <a class="rg-admin-table__primary rg-admin-games__sources-link" href="{{ $row['url'] }}">{{ $row['name'] }}</a>
                                            <div class="rg-admin-table__meta">{{ $row['sub'] }}</div>
                                        </div>
                                        @foreach ($row['cells'] as [$value, $note])
                                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $value }}<span class="rg-admin-table__meta">{{ $note }}</span></div>
                                        @endforeach
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['value'] }}</div>
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $row['cost'] }}@if ($row['costNote'] !== null)<span class="rg-admin-table__meta">{{ $row['costNote'] }}</span>@endif</div>
                                        <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric', 'rg-admin-games__change--good' => $row['roiTone'] === 'good', 'rg-admin-games__change--bad' => $row['roiTone'] === 'bad']) role="cell">{{ $row['roi'] }}@if ($row['roiNote'] !== null)<span class="rg-admin-table__meta">{{ $row['roiNote'] }}</span>@endif</div>
                                    </div>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </x-admin.ui.card>
            @endif

            @unless ($noData)

            <x-admin.ui.card title="Funnel" description="People at every step, each counted once — someone who played three times started once — from the attempts started in this period, however late they finished. Completion above counts plays.">
                <div class="rg-admin-table__scroll">
                    <div @class(['rg-admin-table__grid rg-admin-games__funnel', 'rg-admin-games__funnel--compared' => $compared]) role="table" aria-label="Funnel">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Step</div>
                            <div class="rg-admin-table__cell" role="columnheader"><span class="sr-only">Share of the first step</span></div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Count</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Rate</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">From first</div>
                            @if ($compared)<div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Vs previous period</div>@endif
                        </div>
                        @foreach ($funnel as $step)
                            <div class="rg-admin-table__row" role="row">
                                <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                    <div class="rg-admin-table__primary">{{ $step['label'] }}</div>
                                    @if ($step['hint'] !== null)
                                        <div class="rg-admin-table__meta">{{ $step['hint'] }}</div>
                                    @endif
                                </div>
                                <div class="rg-admin-table__cell" role="cell" aria-hidden="true">
                                    <span class="rg-admin-games__bar"><span style="width: {{ $step['bar'] }}%"></span></span>
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $step['count'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">
                                    {{ $step['rate'] }}
                                    @if ($step['rateOf'] !== null)
                                        <span class="rg-admin-table__meta">{{ $step['rateOf'] }}</span>
                                    @endif
                                </div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ $step['fromStart'] }}</div>
                                @if ($compared)
                                    <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-games__note', 'rg-admin-games__change--good' => ($step['change']['tone'] ?? null) === 'good', 'rg-admin-games__change--bad' => ($step['change']['tone'] ?? null) === 'bad']) role="cell">{{ $step['change']['text'] ?? '' }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-admin.ui.card>
            @endunless

            @if ($signups !== null)
                <x-admin.ui.card title="Sign-ups" :description="$signups['note']" data-testid="game-analytics-signups">
                    @if ($signups['trafficUrl'] !== null)
                        <x-slot:aside>
                            <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$signups['trafficUrl']">Record visits</x-admin.ui.button>
                        </x-slot:aside>
                    @endif
                    <dl class="rg-admin-games__kpis">
                        @foreach ($signups['figures'] as $figure)
                            <div class="rg-admin-games__kpi">
                                <dt class="rg-admin-stat__label">{{ $figure['label'] }}</dt>
                                <dd class="rg-admin-stat__value">{{ $figure['value'] }}</dd>
                                <dd class="rg-admin-games__note">{{ $figure['note'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </x-admin.ui.card>
            @endif

            @unless ($noData)
            @if ($focus !== null)
                <div class="rg-admin-games__analytics-pair">
                    <x-admin.ui.card :title="'Rounds reached · '.$focus['label']" :description="$focus['note']">
                        @if ($focus['rounds'] === [])
                            <p class="rg-admin-games__note">This version has no rounds.</p>
                        @else
                            <ol class="rg-admin-games__meters" data-testid="game-analytics-reach">
                                @foreach ($focus['rounds'] as $round)
                                    <li class="rg-admin-games__meter">
                                        <span class="rg-admin-games__meter-label">
                                            <span class="rg-admin-games__mechanic-name">{{ $round['label'] }}{{ $round['unscored'] ? ' · unscored' : '' }}</span>
                                            <span class="rg-admin-games__note">{{ $round['question'] }}</span>
                                        </span>
                                        <span class="rg-admin-games__bar" aria-hidden="true"><span style="width: {{ $round['bar'] }}%"></span></span>
                                        <span class="rg-admin-games__meter-value">{{ $round['reached'] }}</span>
                                        <span class="rg-admin-games__note rg-admin-games__meter-extra">{{ $round['count'] }}{{ $round['lost'] !== null ? ' · '.$round['lost'] : '' }}</span>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </x-admin.ui.card>

                    <x-admin.ui.card :title="'Results · '.$focus['label']" :description="$focus['resultsNote']">
                        <ol class="rg-admin-games__meters" data-testid="game-analytics-results">
                            @foreach ($focus['results'] as $result)
                                <li class="rg-admin-games__meter">
                                    <span class="rg-admin-games__meter-label">
                                        <span class="rg-admin-games__mechanic-name">{{ $result['label'] }}</span>
                                        <span class="rg-admin-games__note">{{ $result['sub'] }}</span>
                                    </span>
                                    <span class="rg-admin-games__bar" aria-hidden="true"><span style="width: {{ $result['bar'] }}%"></span></span>
                                    <span class="rg-admin-games__meter-value">{{ $result['share'] }}</span>
                                    <span class="rg-admin-games__note rg-admin-games__meter-extra">{{ $result['count'] }}</span>
                                </li>
                            @endforeach
                        </ol>
                    </x-admin.ui.card>
                </div>
            @endif

            <x-admin.ui.card title="Break down by" :description="$breakdownNote" data-testid="game-analytics-breakdown">
                <x-slot:aside>
                    <div class="rg-admin-games__card-tools">
                        <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Break down by">
                            @foreach ($breakdowns as $key => $label)
                                <button type="button" role="radio" aria-checked="{{ $breakdown === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => $breakdown === $key]) wire:click="$set('breakdown', @js($key))">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                </x-slot:aside>
                @if ($breakdownRows['rows'] === [])
                    <p class="rg-admin-games__note">Nothing to compare in this period.</p>
                @else
                    <div class="rg-admin-table__scroll">
                        <div class="rg-admin-table__grid rg-admin-games__breakdown" role="table" aria-label="Break down by {{ strtolower($breakdowns[$breakdown]) }}">
                            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                                @foreach ($breakdownRows['head'] as $index => $column)
                                    <div @class(['rg-admin-table__cell', 'rg-admin-table__cell--numeric' => $index > 0]) role="columnheader">{{ $column }}</div>
                                @endforeach
                            </div>
                            @foreach ($breakdownRows['rows'] as $row)
                                <div class="rg-admin-table__row" role="row">
                                    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                        <div class="rg-admin-table__primary">{{ $row['name'] }}</div>
                                        @if ($row['sub'] !== null && $row['sub'] !== '')
                                            <div class="rg-admin-table__meta">{{ $row['sub'] }}</div>
                                        @endif
                                    </div>
                                    @foreach ($row['cells'] as [$value, $rate])
                                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">
                                            {{ $value }}
                                            @if ($rate !== null)
                                                <span class="rg-admin-table__meta">{{ $rate }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-admin.ui.card>
            @endunless
        @endif

        @if (! $noVersions)
            <x-admin.ui.card :title="'Traffic periods · '.$audiences[$audience].($periodVersion !== null ? ' · '.$periodVersion : '')" :description="$periodsNote" data-testid="game-analytics-periods">
                <x-slot:aside>
                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$trafficUrl">Open Traffic</x-admin.ui.button>
                </x-slot:aside>
                @if ($periodRows === [] && $periodVersion !== null)
                    <p class="rg-admin-games__note" data-testid="game-analytics-periods-none">{{ $periodVersion }} had no share of {{ $anonymous ? 'anonymous visitors' : 'logged-in players' }} in any period yet.</p>
                @elseif ($periodRows === [])
                    <p class="rg-admin-games__note">No periods yet for this group.</p>
                @else
                    <ul class="rg-admin-games__profiles">
                        @foreach ($periodRows as $row)
                            <li class="rg-admin-games__profile">
                                <span class="rg-admin-games__mechanic-text">
                                    <span class="rg-admin-games__mechanic-name">{{ $row['dates'] }}</span>
                                    <span class="rg-admin-games__note">{{ $row['shares'] }} · {{ $row['figures'] }}</span>
                                </span>
                                <span class="rg-admin-games__period-visitors">{{ $row['visitors'] }}</span>
                                @if ($row['badge'] !== null)
                                    <x-admin.ui.badge :tone="$row['badge']['tone']">{{ $row['badge']['label'] }}</x-admin.ui.badge>
                                @endif
                                @if ($row['resultsUrl'] !== null)
                                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$row['resultsUrl']">Results</x-admin.ui.button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.ui.card>
        @endif
    </div>
</div>
