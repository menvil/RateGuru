@php
    /*
     * Growth → Games: the library (ListGames). The header band with what can
     * be counted now; one card: the search over the title, the internal name
     * and the slug, Status, Mechanic and Sort, read on the server; the table
     * and its pages. Header and rows share one grid; where the card is
     * narrower than the grid, the card scrolls sideways and every column
     * stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(260px, 1.8fr) minmax(150px, 1fr) 120px 84px 100px 96px 120px 88px; --rg-admin-table-min-width: 1020px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-games-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-games-title" class="rg-admin-page-header__title">Games</h1>
            <p class="rg-admin-page-header__description">Run interactive games for acquisition and engagement.</p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Games</dt>
                <dd class="rg-admin-stat__value">{{ $stats['total'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Live</dt>
                <dd class="rg-admin-stat__value">{{ $stats['live'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Drafts</dt>
                <dd class="rg-admin-stat__value">{{ $stats['drafts'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Paused</dt>
                <dd class="rg-admin-stat__value">{{ $stats['paused'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search title, name or slug"
                    name="q"
                    id="rg-admin-games-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="Status" id="rg-admin-games-status" :options="$statuses" :value="$status" wire:model.live="status" />
                <x-admin.ui.filter-dropdown label="Mechanic" id="rg-admin-games-mechanic" :options="$mechanics" :value="$mechanic" wire:model.live="mechanic" />
                <x-admin.ui.filter-dropdown label="Sort" id="rg-admin-games-sort" :options="$sorts" :value="$sort" wire:model.live="sort" />
                @if ($statusName !== null)
                    <span class="rg-admin-filter-chip">
                        Status: {{ $statusName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Status: {{ $statusName }}" wire:click="$set('status', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                @if ($mechanicName !== null)
                    <span class="rg-admin-filter-chip">
                        Mechanic: {{ $mechanicName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Mechanic: {{ $mechanicName }}" wire:click="$set('mechanic', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('game', $total) }}{{ $filtered ? ($total === 1 ? ' matches' : ' match') : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Games" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Game</div>
                            <div class="rg-admin-table__cell" role="columnheader">Last version</div>
                            <div class="rg-admin-table__cell" role="columnheader">Status</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader" title="Attempts started in the last 30 days">Plays</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader" title="Attempts completed of those started in the last 30 days">Completion</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader" title="New accounts of anonymous visitors in the last 30 days">Sign-ups</div>
                            <div class="rg-admin-table__cell" role="columnheader">Last updated</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.games.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="games" :note="$sort === 'name' ? 'by name' : 'last updated first'" action="goToPage" label="Games pages" />
            @elseif ($empty && ! $filtered)
                <x-admin.ui.empty-state icon="gamepad-2" title="No games yet">
                    A game is a few rounds of choices with a result at the end. Once one is added, it is listed here.
                </x-admin.ui.empty-state>
            @else
                <x-admin.ui.empty-state icon="search-x" title="No games match these filters">
                    Try a different search, or remove the filters.
                    @if ($filtered)
                        <x-slot:action>
                            <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
                        </x-slot:action>
                    @endif
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>
</div>
