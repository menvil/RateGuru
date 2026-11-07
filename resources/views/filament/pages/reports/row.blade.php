@php
    /*
     * One report (TBL-01): what it points to — a post, a comment or a person,
     * with its id, its state and how many open reports point to it — the
     * reason and who reported it, the report's status and when it came in.
     * The target opens the report's chain. With the chain docked, the row
     * condenses to four columns; otherwise an open report has Resolve and
     * Ignore inline.
     */
    $id = 'rg-admin-report-'.$row['id'];
@endphp

<div
    id="{{ $id }}"
    @class(['rg-admin-table__row', 'rg-admin-reports__row', 'rg-admin-reports__row--selected' => $row['selected']])
    role="row"
    wire:key="report-{{ $row['id'] }}"
    @if ($row['selected']) aria-current="true" @endif
>
    <div class="rg-admin-table__cell rg-admin-reports__full rg-admin-reports__type" role="cell">
        <x-admin.ui.icon :name="$row['icon']" :size="15" />{{ $row['kind'] }}
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <button type="button" class="rg-admin-reports__target" wire:click="select({{ $row['id'] }})" aria-controls="rg-admin-report-panel">
            <span class="rg-admin-reports__tile rg-admin-reports__docked" aria-hidden="true"><x-admin.ui.icon :name="$row['icon']" :size="15" /></span>
            <span class="rg-admin-table__stack">
                <span class="rg-admin-reports__preview rg-admin-table__ellipsis">{{ $row['preview'] }}</span>
                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis"><span class="rg-admin-reports__docked">{{ $row['kind'] }} · </span>{{ $row['meta'] }}</span>
            </span>
        </button>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-table__stack">
            <span class="rg-admin-table__ellipsis">{{ $row['reason'] }}</span>
            <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis rg-admin-reports__docked">by {{ $row['reporter'] }}</span>
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-reports__full" role="cell">
        <span class="rg-admin-table__ellipsis">{{ $row['reporter'] }}</span>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell rg-admin-reports__created" role="cell">{{ $row['created'] }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end rg-admin-reports__full" role="cell">
        @if ($row['open'])
            <x-admin.ui.button size="sm" wire:click="resolve({{ $row['id'] }})" wire:loading.attr="disabled">
                Resolve<span class="rg-admin-sr-only"> report #{{ $row['id'] }}</span>
            </x-admin.ui.button>
            <x-admin.ui.button size="sm" wire:click="ignore({{ $row['id'] }})" wire:loading.attr="disabled">
                Ignore<span class="rg-admin-sr-only"> report #{{ $row['id'] }}</span>
            </x-admin.ui.button>
        @endif
        <x-admin.ui.icon-button icon="panel-right-open" :label="'Open details of report #'.$row['id']" variant="ghost" size="sm" wire:click="select({{ $row['id'] }})" />
    </div>
</div>
