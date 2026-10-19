@php
    /*
     * Content Studio's Publications & plans (ListStudioPlans). The header band
     * with what can be counted now; one card: the search over the title, the
     * id and the author, State and Image, read on the server; the table and
     * its pages. Header and rows share one grid; where the card is narrower
     * than the grid, the card scrolls sideways and every column stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(240px, 1.6fr) minmax(200px, 1.2fr) 72px 150px 110px 130px 150px; --rg-admin-table-min-width: 1060px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-studio-plans-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-studio-plans-title" class="rg-admin-page-header__title">Publications &amp; plans</h1>
            <p class="rg-admin-page-header__description">Prepare publication drafts before reviewing and publishing them.</p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Total plans</dt>
                <dd class="rg-admin-stat__value">{{ $stats['total'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Drafts</dt>
                <dd class="rg-admin-stat__value">{{ $stats['drafts'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">With image</dt>
                <dd class="rg-admin-stat__value">{{ $stats['withImage'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">With author</dt>
                <dd class="rg-admin-stat__value">{{ $stats['withAuthor'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search title, ID or author"
                    name="q"
                    id="rg-admin-studio-plans-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="State" id="rg-admin-studio-plans-state" :options="$states" :value="$state" wire:model.live="state" />
                <x-admin.ui.filter-dropdown label="Image" id="rg-admin-studio-plans-image" :options="$images" :value="$image" wire:model.live="image" />
                @if ($stateName !== null)
                    <span class="rg-admin-filter-chip">
                        State: {{ $stateName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter State: {{ $stateName }}" wire:click="$set('state', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                @if ($imageName !== null)
                    <span class="rg-admin-filter-chip">
                        Image: {{ $imageName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Image: {{ $imageName }}" wire:click="$set('image', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('plan', $total) }}{{ $filtered ? ' match' : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Publication plans" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Publication</div>
                            <div class="rg-admin-table__cell" role="columnheader">Author</div>
                            <div class="rg-admin-table__cell" role="columnheader">Image</div>
                            <div class="rg-admin-table__cell" role="columnheader">Publication date</div>
                            <div class="rg-admin-table__cell" role="columnheader">State</div>
                            <div class="rg-admin-table__cell" role="columnheader">Last updated</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.studio-plans.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="plans" note="last updated first" action="goToPage" label="Publication plans pages" />
            @elseif ($empty && ! $filtered)
                <x-admin.ui.empty-state icon="newspaper" title="No publication plans yet">
                    A plan is a draft of a post, prepared here before it is reviewed and published.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" variant="primary" icon="plus" :href="$createUrl">Create publication</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @else
                <x-admin.ui.empty-state icon="search-x" title="No plans match">
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

    @if ($confirmation !== null)
        @include('filament.pages.studio-plans.confirmation', ['plan' => $confirmation])
    @endif
</div>
