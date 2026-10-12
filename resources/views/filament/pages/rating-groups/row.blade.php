@php
    /*
     * One rating group (TBL-01): its label and description, its key, its
     * options by state, its votes, its active options against its allowed
     * range (STS-04, said in words beside it), whether voters see it and its
     * place on post pages. Edit is inline; the rest is in the row menu
     * (TBL-04).
     */
    $id = 'rg-admin-rating-group-'.$row['id'];
    $active = $row['activeOptions'];

    $meta = array_filter([
        $row['inactiveOptions'] > 0 ? number_format($row['inactiveOptions']).' inactive' : null,
        $row['archivedOptions'] > 0 ? number_format($row['archivedOptions']).' archived' : null,
    ]);

    $range = $row['min'].'–'.$row['max'].' allowed'.match (true) {
        $active < $row['min'] => ' · needs '.($row['min'] - $active).' more',
        $active > $row['max'] => ' · '.($active - $row['max']).' too many',
        $active === $row['max'] => ' · at maximum',
        default => '',
    };

    $activateHint = match (true) {
        $row['valid'] => null,
        $active < $row['min'] => 'Needs '.($row['min'] - $active).' more active '.\Illuminate\Support\Str::plural('option', $row['min'] - $active),
        default => 'Has '.($active - $row['max']).' active '.\Illuminate\Support\Str::plural('option', $active - $row['max']).' too many',
    };
@endphp

<div id="{{ $id }}" class="rg-admin-table__row" role="row" wire:key="rating-group-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <a href="{{ $row['url'] }}" class="rg-admin-taxonomy__name">{{ $row['label'] }}</a>
        @if ($row['description'] !== '')
            <div class="rg-admin-table__meta rg-admin-rating-groups__description" title="{{ $row['description'] }}">{{ $row['description'] }}</div>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-taxonomy__slug" role="cell" title="{{ $row['key'] }}">{{ $row['key'] }}</div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-rating-groups__figure">{{ number_format($active) }} active</div>
        <div class="rg-admin-table__meta rg-admin-table__meta--small">{{ $meta === [] ? 'No inactive options' : implode(' · ', $meta) }}</div>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($row['votes']) }}</div>

    <div class="rg-admin-table__cell rg-admin-rating-groups__range" role="cell">
        <x-admin.ui.range-slots :active="$active" :min="$row['min']" :max="$row['max']" :count="$slotCount" />
        <span @class(['rg-admin-table__meta', 'rg-admin-table__meta--small', 'rg-admin-table__meta--due' => ! $row['valid']])>{{ $range }}</span>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['active'] ? 'success' : 'neutral'" :dot="$row['active']">{{ $row['active'] ? 'Active' : 'Inactive' }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-taxonomy__figure" role="cell">{{ number_format($row['sort']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" :href="$row['url']">
            Edit<span class="rg-admin-sr-only"> {{ $row['label'] }}</span>
        </x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for '.$row['label']">
            <x-admin.ui.menu-item icon="list" :href="$row['url']">Manage options</x-admin.ui.menu-item>

            @if ($row['active'])
                <x-admin.ui.menu-item icon="eye-off" hint="Hide from voters, keep the votes" wire:click="askToDeactivate({{ $row['id'] }})">Deactivate</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="eye" :hint="$activateHint" wire:click="activate({{ $row['id'] }})">Activate</x-admin.ui.menu-item>
            @endif

            @if ($row['translate'] !== null)
                <x-admin.ui.menu-item icon="languages" :href="$row['translate']">Translate</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="languages" hint="Inactive · activate it to translate" disabled>Translate</x-admin.ui.menu-item>
            @endif

            <div class="rg-admin-menu__separator" role="separator"></div>

            <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Groups are kept · deactivate instead" disabled>Delete</x-admin.ui.menu-item>
        </x-admin.ui.row-menu>
    </div>
</div>
