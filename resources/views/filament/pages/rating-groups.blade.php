@php
    /*
     * Rating groups (ManageRatingGroups). Header and rows share one grid;
     * where the card is narrower than the grid, the card scrolls sideways and
     * every column stays (TBL-01). The list is short and has no search.
     */
    $columns = '--rg-admin-table-columns: minmax(240px, 1fr) 130px 170px 100px 150px 100px 64px 120px; --rg-admin-table-min-width: 1040px';
@endphp

<div class="rg-admin rg-admin-screen">
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-rating-groups-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-rating-groups-title" class="rg-admin-page-header__title">Rating groups</h1>
            <p class="rg-admin-page-header__description">
                Voters pick one option in every active group. Each group must keep its number of active options inside its allowed range.
            </p>
        </div>
        <dl class="rg-admin-stats">
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Active groups</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['groups']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Active options</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['options']) }}</dd>
            </div>
            <div class="rg-admin-stat">
                <dt class="rg-admin-stat__label">Votes</dt>
                <dd class="rg-admin-stat__value">{{ number_format($stats['votes']) }}</dd>
            </div>
        </dl>
    </section>

    <div class="rg-admin-screen__body">
        <div class="rg-admin-table">
            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Rating groups" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Label</div>
                            <div class="rg-admin-table__cell" role="columnheader">Key</div>
                            <div class="rg-admin-table__cell" role="columnheader">Options</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Votes</div>
                            <div class="rg-admin-table__cell" role="columnheader">Active range</div>
                            <div class="rg-admin-table__cell" role="columnheader">Active</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Sort</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            @include('filament.pages.rating-groups.row', ['row' => $row, 'slotCount' => $slotCount])
                        @endforeach
                    </div>
                </div>

                <div class="rg-admin-taxonomy__footer">
                    @include('filament.pages.rating-groups.legend')
                </div>
            @else
                <x-admin.ui.empty-state icon="star" title="No rating groups yet">
                    Voters rate posts in rating groups, so the vote form needs at least one active group.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" icon="plus" :href="\App\Filament\Resources\RatingGroups\Pages\CreateRatingGroup::getUrl()">Create group</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @endif
        </div>
    </div>

    @if ($confirmation !== null)
        @include('filament.pages.rating-groups.confirmation', ['row' => $confirmation])
    @endif
</div>
