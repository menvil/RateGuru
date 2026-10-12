@php
    /*
     * Categories (ManageCategories). Header and rows share one grid; where the
     * card is narrower than the grid, the card scrolls sideways and every
     * column stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(240px, 1fr) 190px 120px 110px 90px 120px; --rg-admin-table-min-width: 870px';
    $total = count($rows);

    /*
     * The rows come from the server; the search over them is the browser's.
     * The card is keyed by what its rows show, so when a save, an activation
     * or a deletion changes them, the browser counts afresh.
     */
    $rowsKey = 'rg-admin-categories-rows-'.md5(json_encode(array_map(fn (array $row): array => [$row['id'], $row['search']], array_values($rows))));
    $noMatch = new \Illuminate\Support\HtmlString('Nothing matches “<span x-text="query.trim()"></span>”');
@endphp

<div class="rg-admin rg-admin-screen">
    @include('filament.pages.taxonomy.search-script')

    <section class="rg-admin-page-header" aria-labelledby="rg-admin-categories-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-categories-title" class="rg-admin-page-header__title">Categories</h1>
            <p class="rg-admin-page-header__description">
                Every post belongs to one category. Names here are the English reference; other languages are managed separately.
                @if ($translationCenter !== null)
                    <a href="{{ $translationCenter }}" class="rg-admin-link">Open Translation Center</a>
                @endif
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Active</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['active']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Inactive</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['inactive']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Missing translations</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['missing']) }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body" x-data="rgAdminTaxonomySearch('rg-admin-categories-search')" x-on:popstate.window="query = new URLSearchParams(location.search).get('q') ?? ''">
        <div
            class="rg-admin-table"
            wire:key="{{ $rowsKey }}"
            x-init="rows = [...$el.querySelectorAll('[data-search]')].map((row) => row.dataset.search)"
        >
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search name or slug"
                    name="q"
                    id="rg-admin-categories-search"
                    x-model="query"
                    clearable
                />
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status" x-text="`${shown} of {{ number_format($total) }}`">{{ number_format($total) }} of {{ number_format($total) }}</span>
            </div>

            @if ($total > 0)
                {{-- Opened with a search: the rows wait for the browser to apply it rather than flash unfiltered. --}}
                <div class="rg-admin-table__scroll" @if (request()->filled('q')) x-cloak @endif>
                    <div class="rg-admin-table__grid" role="table" aria-label="Categories" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Name</div>
                            <div class="rg-admin-table__cell" role="columnheader">Slug</div>
                            <div class="rg-admin-table__cell" role="columnheader">Active</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Sort order</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Posts</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.categories.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                {{-- The search matches none of the rows: offer to create what was searched for. --}}
                <div x-cloak x-show="rows.length > 0 && shown === 0">
                    <x-admin.ui.empty-state icon="search-x" :title="$noMatch">
                        Search by name or slug.
                        <x-slot:action>
                            <x-admin.ui.button size="sm" icon="plus" x-on:click="$wire.create(query.trim())">
                                <span>Create “<span x-text="query.trim()"></span>”</span>
                            </x-admin.ui.button>
                        </x-slot:action>
                    </x-admin.ui.empty-state>
                </div>

                <p class="rg-admin-taxonomy__footer">Sorted by sort order. Inactive categories stay attached to their posts.</p>
            @else
                <x-admin.ui.empty-state icon="folder" title="No categories yet">
                    Every post is filed under a category, so uploads need at least one.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" icon="plus" wire:click="create">Create category</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($drawer !== null)
        @include('filament.pages.categories.drawer')
    @endif

    @if ($confirmation !== null)
        @include('filament.pages.categories.confirmation', ['row' => $confirmation])
    @endif
</div>
