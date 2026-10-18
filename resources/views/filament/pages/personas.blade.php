@php
    /*
     * Content Studio's Personas (ListPersonas). The header band with what can
     * be counted now; one card: the search over the username, the names, the
     * short description and the character, Status and Engagement, read on the
     * server; the table and its pages. Header and rows share one grid; where
     * the card is narrower than the grid, the card scrolls sideways and every
     * column stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(220px, 1fr) minmax(240px, 1.4fr) 130px 110px 100px 150px; --rg-admin-table-min-width: 960px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-personas-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-personas-title" class="rg-admin-page-header__title">Personas</h1>
            <p class="rg-admin-page-header__description">
                Regular accounts run by Content Studio. They never sign in; their status changes only through moderation in Users.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Total</dt>
                <dd class="rg-admin-stat__value">{{ $stats['total'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Active</dt>
                <dd class="rg-admin-stat__value">{{ $stats['active'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Restricted</dt>
                <dd class="rg-admin-stat__value">{{ $stats['restricted'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Replies on</dt>
                <dd class="rg-admin-stat__value">{{ $stats['replying'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search username, name or character"
                    name="q"
                    id="rg-admin-personas-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="Status" id="rg-admin-personas-status" :options="$statuses" :value="$status" wire:model.live="status" />
                <x-admin.ui.filter-dropdown label="Engagement" id="rg-admin-personas-engagement" :options="$engagements" :value="$engagement" wire:model.live="engagement" />
                @if ($statusName !== null)
                    <span class="rg-admin-filter-chip">
                        Status: {{ $statusName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Status: {{ $statusName }}" wire:click="$set('status', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                @if ($engagementName !== null)
                    <span class="rg-admin-filter-chip">
                        Engagement: {{ $engagementName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Engagement: {{ $engagementName }}" wire:click="$set('engagement', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('persona', $total) }}{{ $filtered ? ' match' : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Personas" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Persona</div>
                            <div class="rg-admin-table__cell" role="columnheader">Character</div>
                            <div class="rg-admin-table__cell" role="columnheader">Status</div>
                            <div class="rg-admin-table__cell" role="columnheader">Engagement</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Max a day</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.personas.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="personas" note="newest first" action="goToPage" label="Personas pages" />
            @elseif ($empty && ! $filtered)
                <x-admin.ui.empty-state icon="bot" title="No personas yet">
                    A persona is a regular account that Content Studio runs. Create the first one to give it a voice.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" variant="primary" icon="plus" :href="$createUrl">Create persona</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @else
                <x-admin.ui.empty-state icon="search-x" title="No personas match">
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
