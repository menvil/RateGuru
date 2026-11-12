@php
    /*
     * A game's Analytics tab (GameAnalytics): one audience over a window, for
     * every version or one — the figures, the funnel, the rounds reached and
     * the results earned in one version, the versions, flows or content sets
     * side by side, and the traffic periods. Every figure is read from what
     * playing recorded.
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        <div class="rg-admin-games__analytics-filters">
            <x-admin.ui.segmented label="Group" compact :options="$audiences" :value="$audience" wire:model.live="audience" />
            <x-admin.ui.segmented label="Period" compact :options="['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'all' => 'All time']" :value="$period" wire:model.live="period" />
            @if (! $noVersions)
                <x-admin.ui.filter-dropdown label="Version" id="rg-admin-games-analytics-version" :options="$versionOptions" :value="(string) $version" wire:model.live="version" />
            @endif
            <span class="rg-admin-games__note rg-admin-games__analytics-scope" role="status">{{ $scopeText }}</span>
        </div>

        @if ($noVersions)
            <x-admin.ui.card>
                <x-admin.ui.empty-state icon="chart-no-axes-column" title="Nothing to measure yet">
                    Figures start with the first visitor of a published version.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" :href="$versionsUrl">Go to Versions</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            </x-admin.ui.card>
        @elseif ($noData)
            <x-admin.ui.card>
                <x-admin.ui.empty-state icon="chart-no-axes-column" title="No visitors here yet">
                    Nobody of this group opened {{ $version > 0 ? 'this version' : 'the game' }} in this period. Try a longer period or the other group.
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
                        </div>
                    @endforeach
                </dl>
            </x-admin.ui.card>

            <x-admin.ui.card title="Funnel" description="People at every step, each counted once — someone who played three times started once — from the attempts started in this period, however late they finished. Completion above counts plays.">
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid rg-admin-games__funnel" role="table" aria-label="Funnel">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Step</div>
                            <div class="rg-admin-table__cell" role="columnheader"><span class="sr-only">Share of the first step</span></div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Count</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Rate</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">From first</div>
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
                            </div>
                        @endforeach
                    </div>
                </div>
            </x-admin.ui.card>

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

            <x-admin.ui.card title="Side by side" description="Versions are a fair comparison only inside one traffic period; a person who saw two versions counts in each.">
                <x-slot:aside>
                    <x-admin.ui.segmented label="Break down by" compact :options="$breakdowns" :value="$breakdown" wire:model.live="breakdown" />
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
                                        <div class="rg-admin-table__meta">{{ $row['sub'] }}</div>
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
        @endif

        @if (! $noVersions)
            <x-admin.ui.card :title="'Traffic periods · '.$audiences[$audience]" description="A new period starts whenever the shares change. Versions are only a fair comparison inside one period.">
                <x-slot:aside>
                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$trafficUrl">Open Traffic</x-admin.ui.button>
                </x-slot:aside>
                @if ($periodRows === [])
                    <p class="rg-admin-games__note">No periods yet for this group.</p>
                @else
                    <ul class="rg-admin-games__profiles">
                        @foreach ($periodRows as $row)
                            <li class="rg-admin-games__profile">
                                <span class="rg-admin-games__mechanic-text">
                                    <span class="rg-admin-games__mechanic-name">{{ $row['dates'] }}</span>
                                    <span class="rg-admin-games__note">{{ $row['shares'] }} · {{ $row['figures'] }}</span>
                                </span>
                                <x-admin.ui.badge :tone="$row['current'] ? 'success' : 'neutral'">{{ $row['current'] ? 'Current' : 'Ended' }}</x-admin.ui.badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-admin.ui.card>
        @endif
    </div>
</div>
