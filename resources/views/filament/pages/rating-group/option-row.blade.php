@php
    /*
     * One option (TBL-01): label and description, key, its state (STS-01 —
     * active with the live dot, inactive neutral, archived outline), place,
     * votes and archive date. Edit is inline; the menu (TBL-04) holds
     * Activate or Deactivate — the hint says when the range will refuse it,
     * and the dialog then says what to do — Archive or Unarchive, and Delete
     * unused option, which an option with votes is offered the archive for.
     */
    $id = 'rg-admin-rating-option-'.$option['id'];
    $archived = $option['archived'] !== null;

    [$stateLabel, $stateTone] = match (true) {
        $option['active'] => ['Active', 'success'],
        $archived => ['Archived', 'outline'],
        default => ['Inactive', 'neutral'],
    };

    $toggleHint = match (true) {
        $option['active'] && ! $group->canSpareActiveOption($active) => 'Blocked · minimum is '.$group->min_options,
        ! $option['active'] && ! $group->hasRoomForActiveOption($active) => 'Blocked · maximum is '.$group->max_options,
        default => null,
    };
@endphp

<div id="{{ $id }}" @class(['rg-admin-table__row', 'rg-admin-rating-group__option--archived' => $archived]) role="row" wire:key="rating-option-{{ $option['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div @class(['rg-admin-table__primary', 'rg-admin-taxonomy__name--off' => $archived])>{{ $option['label'] }}</div>
        @if ($option['description'] !== '')
            <div class="rg-admin-table__meta rg-admin-rating-groups__description" title="{{ $option['description'] }}">{{ $option['description'] }}</div>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-taxonomy__slug" role="cell" title="{{ $option['key'] }}">{{ $option['key'] }}</div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$stateTone" :dot="$option['active']">{{ $stateLabel }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-taxonomy__figure" role="cell">{{ number_format($option['sort']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($option['votes']) }}</div>

    <div class="rg-admin-table__cell rg-admin-taxonomy__figure" role="cell">
        @if ($archived)
            {{ $option['archived'] }}
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">Not archived</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" wire:click="editOption({{ $option['id'] }})">
            Edit<span class="rg-admin-sr-only"> {{ $option['label'] }}</span>
        </x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for '.$option['label']">
            @if ($archived)
                <x-admin.ui.menu-item icon="archive-restore" hint="Returns as inactive" wire:click="unarchiveOption({{ $option['id'] }})">Unarchive</x-admin.ui.menu-item>
            @else
                @if ($option['active'])
                    <x-admin.ui.menu-item icon="eye-off" :hint="$toggleHint" wire:click="deactivateOption({{ $option['id'] }})">Deactivate</x-admin.ui.menu-item>
                @else
                    <x-admin.ui.menu-item icon="eye" :hint="$toggleHint" wire:click="activateOption({{ $option['id'] }})">Activate</x-admin.ui.menu-item>
                @endif
                <x-admin.ui.menu-item icon="archive" hint="Hide from voters, keep votes" wire:click="askToArchive({{ $option['id'] }})">Archive</x-admin.ui.menu-item>
            @endif

            <div class="rg-admin-menu__separator" role="separator"></div>

            @if ($option['votes'] > 0)
                <x-admin.ui.menu-item icon="trash-2" hint="Has votes · archive instead" wire:click="askToDeleteOption({{ $option['id'] }})">Delete unused option</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Never voted on" wire:click="askToDeleteOption({{ $option['id'] }})">Delete unused option</x-admin.ui.menu-item>
            @endif
        </x-admin.ui.row-menu>
    </div>
</div>
