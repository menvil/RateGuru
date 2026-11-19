@php
    /*
     * Analytics → Landings (LandingsTab), as the reference draws it: the
     * landing pages, one row per page a session began on and the experience
     * it showed — where it is with what its version had of the traffic, the
     * game, the version's flow, then the visitors and their rates — a game's
     * row leading to the game's own analytics on the Games tab; then the
     * comparison still open that started last, each version against the
     * reference, with the way to its results on the Experiments tab. Both
     * tables scroll sideways inside their cards where the card is narrower.
     * Nobody matching the filters is said once, with nothing else drawn.
     */
@endphp

@if ($data['empty'])
    @include('filament.pages.analytics.elements.no-visitors', ['range' => $data['range'], 'filtered' => $data['filtered']])
@else
    <x-admin.ui.card title="Landing page performance" :description="$data['description']" flush data-testid="analytics-landings">
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-analytics__landings" role="table" aria-label="Landing pages">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">Landing page</div>
                    <div class="rg-admin-table__cell" role="columnheader">Game</div>
                    <div class="rg-admin-table__cell" role="columnheader">Experience</div>
                    @foreach (['Visitors', 'Start rate', 'Completion', 'Registration', 'Activation'] as $column)
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                    @endforeach
                </div>
                @foreach ($data['rows'] as $row)
                    <div
                        @class(['rg-admin-table__row rg-admin-analytics__landing-row', 'rg-admin-analytics__landing-row--link' => $row['gameUrl'] !== null])
                        role="row"
                        data-testid="analytics-landing-row"
                        wire:key="analytics-landing-{{ $row['key'] ?? 'rest-'.$loop->index }}"
                        @if ($row['gameUrl'] !== null) x-on:click="if (! $event.target.closest('a, button')) window.location.href = @js($row['gameUrl'])" @endif
                    >
                        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                            <div class="rg-admin-table__stack">
                                <span class="rg-admin-analytics__landing-path" title="{{ $row['path'] }}">{{ $row['path'] }}</span>
                                @if ($row['note'] !== null)
                                    <span class="rg-admin-analytics__landing-note">{{ $row['note'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="rg-admin-table__cell" role="cell">
                            @if ($row['gameUrl'] !== null)
                                <a href="{{ $row['gameUrl'] }}" class="rg-admin-analytics__landing-game rg-admin-analytics__landing-game--link" title="The analytics of {{ $row['game'] }}">{{ $row['game'] }}</a>
                            @else
                                <span @class(['rg-admin-analytics__landing-game', 'rg-admin-analytics__landing-quiet' => $row['key'] === null || $row['game'] === '—'])>{{ $row['game'] }}</span>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell rg-admin-analytics__landing-experience" role="cell">{{ $row['experience'] }}</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__count" role="cell">{{ $row['visitors'] }}</div>
                        @foreach ($row['rates'] as $rate)
                            <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric', 'rg-admin-analytics__landing-quiet' => $rate['quiet']]) role="cell" @if ($rate['title'] !== null) title="{{ $rate['title'] }}" @endif>{{ $rate['value'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        <x-slot:footer data-testid="analytics-landings-foot">{{ $data['foot'] }}</x-slot:footer>
    </x-admin.ui.card>

    @if ($data['comparison'] !== null)
        @php($compare = $data['comparison'])
        <x-admin.ui.card :title="$compare['title']" :description="$compare['description']" flush clip class="rg-admin-analytics__compare-card" data-testid="analytics-landings-compare" wire:key="analytics-landings-compare-{{ $compare['id'] }}">
            <x-slot:aside>
                <div class="rg-admin-analytics__compare-actions">
                    <x-admin.ui.badge :tone="$compare['status']['tone']" :dot="$compare['status']['dot']">{{ $compare['status']['label'] }}</x-admin.ui.badge>
                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$compare['url']" data-testid="analytics-landings-results">Experiment results</x-admin.ui.button>
                </div>
            </x-slot:aside>
            @if ($compare['fresh'] !== null)
                <x-admin.ui.empty-state icon="hourglass" title="No visitors yet" class="rg-admin-analytics__compare-fresh" data-testid="analytics-landings-compare-fresh">{{ $compare['fresh'] }}</x-admin.ui.empty-state>
            @else
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid rg-admin-analytics__compare" role="table" aria-label="Versions compared" style="{{ $compare['columns'] }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head rg-admin-analytics__compare-row" role="row">
                            <div class="rg-admin-table__cell rg-admin-analytics__compare-metric" role="columnheader">Metric</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $compare['heads']['reference'] }}</div>
                            @foreach ($compare['heads']['groups'] as $head)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $head }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Difference</div>
                            @endforeach
                        </div>
                        @foreach ($compare['rows'] as $row)
                            <div class="rg-admin-table__row rg-admin-analytics__compare-row" role="row" data-testid="analytics-landings-compare-row">
                                <div class="rg-admin-table__cell rg-admin-analytics__compare-metric rg-admin-analytics__compare-label" role="rowheader">{{ $row['label'] }}</div>
                                <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__compare-figure', 'rg-admin-analytics__landing-quiet' => $row['reference']['quiet']]) role="cell" @if ($row['reference']['title'] !== null) title="{{ $row['reference']['title'] }}" @endif>{{ $row['reference']['value'] }}</div>
                                @foreach ($row['groups'] as $group)
                                    <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__compare-figure', 'rg-admin-analytics__landing-quiet' => $group['quiet']]) role="cell" @if ($group['title'] !== null) title="{{ $group['title'] }}" @endif>{{ $group['value'] }}</div>
                                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__compare-change" role="cell">{{ $group['change'] }}</div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-admin.ui.card>
    @endif
@endif
