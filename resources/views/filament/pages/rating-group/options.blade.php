@php
    /*
     * The group's options (LAY-02 card over TBL-01): what each is called and
     * what it means, its key, whether voters see it, its place, its votes and
     * when it was archived. Create option opens the drawer; at the maximum an
     * info strip says new options are created inactive.
     */
    $columns = '--rg-admin-table-columns: minmax(220px, 1fr) 150px 110px 60px 100px 130px 120px; --rg-admin-table-min-width: 900px';
    $atMaximum = ! $group->hasRoomForActiveOption($active);
@endphp

<x-admin.ui.card flush>
    <div class="rg-admin-rating-group__options-header">
        <div class="rg-admin-rating-group__options-title">
            <h2 class="rg-admin-card__title">Options</h2>
            <p class="rg-admin-card__description">{{ $active }} active · {{ $counts['inactive'] }} inactive · {{ $counts['archived'] }} archived</p>
        </div>
        <x-admin.ui.button size="sm" icon="plus" wire:click="createOption">Create option</x-admin.ui.button>
    </div>

    @if ($atMaximum)
        <x-admin.ui.inline-notice tone="info" strip>
            {{ $active }} of {{ $group->max_options }} active options. New and unarchived options stay inactive until one is deactivated or the maximum is raised.
        </x-admin.ui.inline-notice>
    @endif

    @if ($options !== [])
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid" role="table" aria-label="Options of {{ $group->label }}" style="{{ $columns }}">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">Label</div>
                    <div class="rg-admin-table__cell" role="columnheader">Key</div>
                    <div class="rg-admin-table__cell" role="columnheader">Active</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Sort</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Votes</div>
                    <div class="rg-admin-table__cell" role="columnheader">Archived</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                </div>

                @foreach ($options as $option)
                    @include('filament.pages.rating-group.option-row', ['option' => $option])
                @endforeach
            </div>
        </div>
    @else
        <x-admin.ui.empty-state icon="list" title="No options yet">
            Voters pick one of a group's active options. It needs {{ $group->min_options }}–{{ $group->max_options }} of them to be active.
        </x-admin.ui.empty-state>
    @endif

    <p class="rg-admin-taxonomy__footer">Options with vote history are archived instead of deleted, so past results stay intact.</p>
</x-admin.ui.card>
