@php
    /*
     * Tags (ManageTags). Header and rows share one grid; where the card is
     * narrower than the grid, the card scrolls sideways and every column
     * stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(240px, 1fr) 240px 100px 120px; --rg-admin-table-min-width: 700px';
    $total = count($rows);

    /*
     * The rows come from the server; the search over them is the browser's.
     * The card is keyed by what its rows show, so when a save, an activation
     * or a deletion changes them, the browser counts afresh.
     */
    $rowsKey = 'rg-admin-tags-rows-'.md5(json_encode(array_map(fn (array $row): array => [$row['id'], $row['search']], array_values($rows))));
    $noMatch = new \Illuminate\Support\HtmlString('Nothing matches “<span x-text="query.trim()"></span>”');
@endphp

<div class="rg-admin rg-admin-screen">
    @include('filament.pages.taxonomy.search-script')

    <section class="rg-admin-page-header" aria-labelledby="rg-admin-tags-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-tags-title" class="rg-admin-page-header__title">Tags</h1>
            <p class="rg-admin-page-header__description">
                Tags are free-form labels on posts. Names here are the English reference; other languages are managed separately.
                @if ($translationCenter !== null)
                    <a href="{{ $translationCenter }}" class="rg-admin-link">Open Translation Center</a>
                @endif
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Tags</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['tags']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Unused</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['unused']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Missing translations</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['missing']) }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body" x-data="rgAdminTaxonomySearch('rg-admin-tags-search')" x-on:popstate.window="query = new URLSearchParams(location.search).get('q') ?? ''">
        <div
            class="rg-admin-table"
            wire:key="{{ $rowsKey }}"
            x-init="rows = [...$el.querySelectorAll('[data-search]')].map((row) => row.dataset.search)"
        >
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search tags"
                    name="q"
                    id="rg-admin-tags-search"
                    x-model="query"
                    clearable
                />
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status" x-text="`${shown} of {{ number_format($total) }}`">{{ number_format($total) }} of {{ number_format($total) }}</span>
            </div>

            @if ($total > 0)
                {{-- Opened with a search: the rows wait for the browser to apply it rather than flash unfiltered. --}}
                <div class="rg-admin-table__scroll" @if (request()->filled('q')) x-cloak @endif>
                    <div class="rg-admin-table__grid" role="table" aria-label="Tags" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Name</div>
                            <div class="rg-admin-table__cell" role="columnheader">Slug</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Posts</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.tags.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                {{-- The search matches none of the rows: offer to create what was searched for. --}}
                <div x-cloak x-show="rows.length > 0 && shown === 0">
                    <x-admin.ui.empty-state icon="search-x" :title="$noMatch">
                        Search by name or slug.
                        @if ($canCreate)
                            <x-slot:action>
                                <x-admin.ui.button size="sm" icon="plus" x-on:click="$wire.create(query.trim())">
                                    <span>Create “<span x-text="query.trim()"></span>”</span>
                                </x-admin.ui.button>
                            </x-slot:action>
                        @endif
                    </x-admin.ui.empty-state>
                </div>

                <p class="rg-admin-taxonomy__footer">Sorted by number of posts.</p>
            @else
                <x-admin.ui.empty-state icon="tag" title="No tags yet">
                    Visitors choose tags for their posts from this list when they upload.
                    @if ($canCreate)
                        <x-slot:action>
                            <x-admin.ui.button size="sm" icon="plus" wire:click="create">Create tag</x-admin.ui.button>
                        </x-slot:action>
                    @endif
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($drawer !== null)
        @include('filament.pages.tags.drawer')
    @endif

    @if ($confirmation !== null)
        @include('filament.pages.tags.confirmation', ['row' => $confirmation])
    @endif
</div>
