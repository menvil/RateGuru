@php
    /*
     * Posts (ListPosts). The tabs, then one card: the toolbar — the search
     * over the title, the author and the post's id, the category and the
     * order, each read on the server — the bulk bar while rows are selected,
     * the table and its pages. Header and rows share one grid; where the card
     * is narrower than the grid, the card scrolls sideways and every column
     * stays (TBL-01).
     */
    use App\Filament\Resources\Posts\Pages\ListPosts;

    $columns = '--rg-admin-table-columns: 44px 56px minmax(200px, 1fr) 176px 140px 80px 136px 192px; --rg-admin-table-min-width: 1024px';
    $total = $paginator->total();
    $ids = array_map(fn (array $row): string => (string) $row['id'], $rows);
    $result = number_format($total).' '.\Illuminate\Support\Str::plural('post', $total).($filtered ? ' match' : '');
    $categoryOptions = [['value' => '', 'label' => 'All categories', 'trigger' => 'All'], ...$categories];
    $sortOptions = collect(ListPosts::SORTS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all();
@endphp

{{-- Refresh in the top bar reads the list again; its rows wait as skeletons meanwhile (TBL-06). --}}
<div
    class="rg-admin rg-admin-screen"
    x-data="{ loading: false }"
    x-on:refresh-posts.window="loading = true; $wire.refresh().finally(() => (loading = false))"
>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-posts-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-posts-title" class="rg-admin-page-header__title">Posts</h1>
            <p class="rg-admin-page-header__description">
                Posts wait here before publishing while their author isn’t trusted or hasn’t confirmed their email. Everyone else publishes at once.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Waiting</dt>
                <dd class="rg-admin-stat__value">{{ $stats['waiting'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Oldest waiting</dt>
                <dd class="rg-admin-stat__value">{{ $stats['oldest'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Reported</dt>
                <dd class="rg-admin-stat__value">{{ $stats['reported'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.tabs label="Posts by status" :active="$status" :items="$tabs" />

        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search"
                    placeholder="Search title, author or post ID"
                    name="q"
                    id="rg-admin-posts-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                <x-admin.ui.filter-dropdown label="Category" id="rg-admin-posts-category" :options="$categoryOptions" :value="$category" wire:model.live="category" />
                {{-- The order a tab is read in unless one is chosen: the menu shows it, and a choice is sent as the order. --}}
                <div class="rg-admin-posts__sort" wire:key="posts-sort-{{ $order }}" x-data="{ order: @js($order) }" x-init="$watch('order', (value) => $wire.set('sort', value))">
                    <x-admin.ui.filter-dropdown label="Sort" id="rg-admin-posts-sort" :options="$sortOptions" :value="$order" x-model="order" />
                </div>
                @if ($categoryName !== null)
                    <span class="rg-admin-filter-chip">
                        Category: {{ $categoryName }}
                        <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove filter Category: {{ $categoryName }}" wire:click="$set('category', '')">
                            <x-admin.ui.icon name="x" :size="12" />
                        </button>
                    </span>
                @endif
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ $result }}</span>
            </div>

            @if ($selection !== null)
                <x-admin.ui.bulk-bar
                    :count="$selection['count']"
                    :note="$selection['skipped'] > 0 ? $selection['skipped'].' selected '.($selection['skipped'] === 1 ? 'post is' : 'posts are').' neither pending nor published and will be skipped' : null"
                    clear="$wire.clearSelection()"
                >
                    <x-admin.ui.button size="sm" variant="primary" icon="check" :disabled="$selection['pending'] === 0" wire:click="approveSelected" wire:loading.attr="disabled">
                        Approve selected{{ $selection['pending'] > 0 ? ' ('.$selection['pending'].')' : '' }}
                    </x-admin.ui.button>
                    <x-admin.ui.button size="sm" icon="eye-off" :disabled="$selection['published'] === 0" wire:click="askToHideSelected">
                        Hide selected{{ $selection['published'] > 0 ? ' ('.$selection['published'].')' : '' }}
                    </x-admin.ui.button>
                </x-admin.ui.bulk-bar>
            @endif

            <div class="rg-admin-table__scroll" x-cloak x-show="loading" aria-busy="true">
                <span class="rg-admin-sr-only">Loading posts…</span>
                <div class="rg-admin-table__grid" style="{{ $columns }}">
                    @foreach (['62%', '48%', '70%', '55%', '40%', '66%'] as $width)
                        <div class="rg-admin-table__row" aria-hidden="true">
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="18px" height="18px" shape="box" tone="soft" /></div>
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="40px" height="40px" shape="box" tone="soft" /></div>
                            <div class="rg-admin-table__cell rg-admin-posts__skeleton-stack">
                                <x-admin.ui.skeleton :width="$width" height="10px" />
                                <x-admin.ui.skeleton width="90px" height="8px" tone="soft" />
                            </div>
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="110px" height="10px" tone="soft" /></div>
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="72px" height="22px" shape="box" tone="soft" /></div>
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="24px" height="10px" tone="soft" /></div>
                            <div class="rg-admin-table__cell"><x-admin.ui.skeleton width="76px" height="10px" tone="soft" /></div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end"><x-admin.ui.skeleton width="120px" height="32px" shape="control" tone="soft" /></div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div x-show="! loading">
                @if ($rows !== [])
                    <div class="rg-admin-table__scroll">
                        <div class="rg-admin-table__grid" role="table" aria-label="Posts" style="{{ $columns }}">
                            <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                                <div class="rg-admin-table__cell" role="columnheader">
                                    <x-admin.ui.checkbox
                                        label="Select every post on this page"
                                        hide-label
                                        id="rg-admin-posts-select-page"
                                        :checked="$selection !== null"
                                        x-bind:checked="$wire.selected.length > 0"
                                        x-effect="$el.indeterminate = $wire.selected.length > 0 && ! {{ \Illuminate\Support\Js::from($ids) }}.every((id) => $wire.selected.includes(id))"
                                        x-on:click.prevent="$wire.togglePage()"
                                    />
                                </div>
                                <div class="rg-admin-table__cell" role="columnheader">Image</div>
                                <div class="rg-admin-table__cell" role="columnheader">Title</div>
                                <div class="rg-admin-table__cell" role="columnheader">Author</div>
                                <div class="rg-admin-table__cell" role="columnheader">Status</div>
                                <div class="rg-admin-table__cell" role="columnheader">Reports</div>
                                <div class="rg-admin-table__cell" role="columnheader">Created</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                            </div>

                            @foreach ($rows as $row)
                                @include('filament.pages.posts.row', ['row' => $row])
                            @endforeach
                        </div>
                    </div>

                    <x-admin.ui.pagination :paginator="$paginator" noun="posts" :note="\Illuminate\Support\Str::lower(ListPosts::SORTS[$order])" action="goToPage" label="Posts pages" />
                @elseif ($filtered)
                    <x-admin.ui.empty-state icon="search-x" title="No posts match these filters">
                        Try a different search or remove the category filter.
                        <x-slot:action>
                            <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
                        </x-slot:action>
                    </x-admin.ui.empty-state>
                @elseif ($status === 'pending')
                    <x-admin.ui.empty-state icon="circle-check" tone="success" title="The queue is clear">
                        Every submitted post has been reviewed. New submissions appear here when you refresh.
                    </x-admin.ui.empty-state>
                @else
                    <x-admin.ui.empty-state icon="circle-check" title="Nothing here">
                        No posts are in this list right now.
                    </x-admin.ui.empty-state>
                @endif
            </div>
        </div>
    </div>

    @if ($confirmation !== null)
        @include('filament.pages.posts.confirmation')
    @endif

    @if ($viewer !== null)
        @include('filament.pages.posts.viewer')
    @endif
</div>
