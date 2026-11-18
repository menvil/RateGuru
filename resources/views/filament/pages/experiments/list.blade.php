@php
    /*
     * The list of Growth → Experiments: the header band, then one card — the
     * search over the games, Show and Status, how many periods match — and
     * the periods, the latest started first (TBL-01): each with its game,
     * the audience it shares, its days, its versions' shares, its visitors,
     * whether its versions can be compared yet and whether it is still
     * open. A row, and its Results, open the period's results. Header and
     * rows share one grid; where the card is narrower, it scrolls sideways.
     */
    $columns = '--rg-admin-table-columns: minmax(200px, 1.2fr) 170px 200px minmax(150px, 1fr) 96px 120px 100px 112px; --rg-admin-table-min-width: 1148px';
@endphp

<section class="rg-admin-page-header rg-admin-experiments__header" aria-labelledby="rg-admin-experiments-title">
    <div class="rg-admin-page-header__text">
        <h1 id="rg-admin-experiments-title" class="rg-admin-page-header__title">Experiments</h1>
        <p class="rg-admin-page-header__description">Every Apply in a game’s Traffic tab starts a period. When a period has two or more versions, it compares them on the same metrics. Nothing to set up here.</p>
    </div>
</section>

<div class="rg-admin-screen__body">
    <div class="rg-admin-table">
        <div class="rg-admin-toolbar">
            <x-admin.ui.search-field
                class="rg-admin-toolbar__search rg-admin-experiments__search"
                placeholder="Search games"
                name="q"
                id="rg-admin-experiments-search"
                :value="$q"
                wire:model.live.debounce.300ms="q"
                clearable
            />
            <x-admin.ui.segmented label="Show" :options="$shows" :value="$show" wire:model.live="show" />
            <x-admin.ui.segmented label="Status" :options="$statuses" :value="$status" wire:model.live="status" />
            <span class="rg-admin-toolbar__count" role="status">{{ $count }}</span>
        </div>

        @if ($rows !== [])
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid" role="table" aria-label="Traffic periods" style="{{ $columns }}">
                    <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                        <div class="rg-admin-table__cell" role="columnheader">Game</div>
                        <div class="rg-admin-table__cell" role="columnheader">Group</div>
                        <div class="rg-admin-table__cell" role="columnheader">Period</div>
                        <div class="rg-admin-table__cell" role="columnheader">Versions</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Visitors</div>
                        <div class="rg-admin-table__cell" role="columnheader">Data</div>
                        <div class="rg-admin-table__cell" role="columnheader">Status</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader"><span class="rg-admin-sr-only">Results</span></div>
                    </div>

                    @foreach ($rows as $row)
                        <div
                            id="rg-admin-experiments-period-{{ $row['id'] }}"
                            class="rg-admin-table__row rg-admin-experiments__row"
                            role="row"
                            wire:key="experiments-period-{{ $row['id'] }}"
                            x-on:click="if (! $event.target.closest('a, button')) window.location.href = @js($row['url'])"
                        >
                            <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-experiments__game" role="cell">
                                <span class="rg-admin-experiments__glyph" title="{{ $row['mechanic'] }}"><x-admin.ui.icon :name="$row['icon']" :size="15" /></span>
                                <div class="rg-admin-table__stack">
                                    <span class="rg-admin-experiments__game-title rg-admin-table__ellipsis">{{ $row['game'] }}</span>
                                    <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['mechanic'] }}</span>
                                </div>
                            </div>
                            <div class="rg-admin-table__cell rg-admin-experiments__group" role="cell">
                                <x-admin.ui.icon :name="$row['audienceIcon']" :size="14" />{{ $row['audience'] }}
                            </div>
                            <div class="rg-admin-table__cell" role="cell">
                                <div class="rg-admin-table__stack">
                                    <span class="rg-admin-experiments__dates">{{ $row['dates'] }}</span>
                                    <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['days'] }}</span>
                                </div>
                            </div>
                            <div class="rg-admin-table__cell" role="cell">
                                <span class="rg-admin-experiments__mix rg-admin-table__ellipsis" title="{{ $row['mix'] }}">{{ $row['mix'] }}</span>
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__visitors" role="cell">{{ $row['visitors'] }}</div>
                            <div class="rg-admin-table__cell" role="cell">
                                <x-admin.ui.badge :tone="$row['state']['tone']">{{ $row['state']['label'] }}</x-admin.ui.badge>
                            </div>
                            <div class="rg-admin-table__cell" role="cell">
                                <x-admin.ui.badge :tone="$row['current'] ? 'success' : 'neutral'" :dot="$row['current']">{{ $row['current'] ? 'Current' : 'Ended' }}</x-admin.ui.badge>
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$row['url']" :aria-label="'Results of '.$row['game'].', '.$row['audience'].', '.$row['dates']">Results</x-admin.ui.button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($paginator->lastPage() > 1)
                <x-admin.ui.pagination :paginator="$paginator" noun="periods" note="latest started first" action="goToPage" label="Experiments pages" />
            @endif
        @else
            <p class="rg-admin-experiments__empty">{{ $emptyText }}</p>
        @endif

        <p class="rg-admin-experiments__foot">Shares change in each game’s Traffic tab. Periods can’t be edited here.</p>
    </div>
</div>
