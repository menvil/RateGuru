@php
    /*
     * One category (TBL-01): its English name with its translation status
     * (STS-05), the slug, whether it is active (STS-01: success with the live
     * dot, or neutral), its place in the curated order and its posts. Edit
     * is inline; the rest is in the row menu (TBL-04), Delete included even
     * while posts hold the category — the dialog then says why not and
     * offers Deactivate instead.
     */
    $id = 'rg-admin-category-'.$row['id'];

    $translateReason = $row['active'] ? 'Only administrators translate' : 'Inactive · activate it to translate';
@endphp

<div
    id="{{ $id }}"
    class="rg-admin-table__row"
    role="row"
    wire:key="category-{{ $row['id'] }}"
    data-search="{{ $row['search'] }}"
    x-show="matches($el.dataset.search)"
>
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        {{-- A real link to the drawer, so it opens in a new tab too; a click opens it in place. --}}
        <a
            href="{{ $row['url'] }}"
            @class(['rg-admin-taxonomy__name', 'rg-admin-taxonomy__name--off' => ! $row['active']])
            wire:click.prevent="edit({{ $row['id'] }})"
        >{{ $row['name'] }}</a>
        @include('filament.pages.taxonomy.chips', ['chips' => $row['chips'], 'missing' => $row['active'] ? $row['missing'] : 0])
    </div>

    <div class="rg-admin-table__cell rg-admin-taxonomy__slug" role="cell" title="{{ $row['slug'] }}">{{ $row['slug'] }}</div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['active'] ? 'success' : 'neutral'" :dot="$row['active']">{{ $row['active'] ? 'Active' : 'Inactive' }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-taxonomy__figure" role="cell">{{ number_format($row['sort']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($row['posts']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" wire:click="edit({{ $row['id'] }})">
            Edit<span class="rg-admin-sr-only"> {{ $row['name'] }}</span>
        </x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for '.$row['name']">
            @if ($row['active'])
                <x-admin.ui.menu-item icon="eye-off" hint="Hide from navigation and uploads" wire:click="deactivate({{ $row['id'] }})">Deactivate</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="eye" hint="Show to visitors again" wire:click="activate({{ $row['id'] }})">Activate</x-admin.ui.menu-item>
            @endif

            @if ($row['translate'] !== null)
                <x-admin.ui.menu-item icon="languages" :href="$row['translate']">Translate</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="languages" :hint="$translateReason" disabled>Translate</x-admin.ui.menu-item>
            @endif

            <div class="rg-admin-menu__separator" role="separator"></div>

            <x-admin.ui.menu-item
                icon="trash-2"
                tone="danger"
                :hint="$row['retained'] ? 'Has posts · deactivate instead' : 'Unused'"
                wire:click="askToDelete({{ $row['id'] }})"
            >Delete</x-admin.ui.menu-item>
        </x-admin.ui.row-menu>
    </div>
</div>
