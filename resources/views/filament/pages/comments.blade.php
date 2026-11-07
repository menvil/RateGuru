@php
    /*
     * Comments (ListComments). The tabs, then one card: the search over the
     * text, the author, the post and the comment's id, read on the server,
     * the table and its pages. Header and rows share one grid; where the card
     * is narrower than the grid, the card scrolls sideways and every column
     * stays (TBL-01).
     */
    $columns = '--rg-admin-table-columns: minmax(240px, 1fr) 150px 180px 80px 170px 120px 140px; --rg-admin-table-min-width: 1080px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-comments-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-comments-title" class="rg-admin-page-header__title">Comments</h1>
            <p class="rg-admin-page-header__description">
                Comments publish immediately. Moderation happens after the fact, mostly from reports.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Reported</dt>
                <dd class="rg-admin-stat__value">{{ $stats['reported'] }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Hidden</dt>
                <dd class="rg-admin-stat__value">{{ $stats['hidden'] }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.tabs label="Comments by state" :active="$status" :items="$tabs" />

        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <x-admin.ui.search-field
                    class="rg-admin-toolbar__search rg-admin-comments__search"
                    placeholder="Search comment text, author or post"
                    name="q"
                    id="rg-admin-comments-search"
                    :value="$q"
                    wire:model.live.debounce.300ms="q"
                    clearable
                />
                {{-- Always present, so a screen reader hears the new count while focus stays in the field. --}}
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('comment', $total) }}{{ $searching ? ' match' : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Comments" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Comment</div>
                            <div class="rg-admin-table__cell" role="columnheader">Author</div>
                            <div class="rg-admin-table__cell" role="columnheader">Post</div>
                            <div class="rg-admin-table__cell" role="columnheader">Reports</div>
                            <div class="rg-admin-table__cell" role="columnheader">State</div>
                            <div class="rg-admin-table__cell" role="columnheader">Created</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.comments.row', ['row' => $row])
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="comments" note="newest first" action="goToPage" label="Comments pages" />
            @else
                <x-admin.ui.empty-state icon="message-square" title="No comments here">
                    Nothing matches this state{{ $searching ? ' and search' : '' }}.
                    @if ($searching)
                        <x-slot:action>
                            <x-admin.ui.button size="sm" wire:click="clearSearch">Clear search</x-admin.ui.button>
                        </x-slot:action>
                    @endif
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($confirmation !== null)
        @include('filament.pages.comments.confirmation')
    @endif
</div>
