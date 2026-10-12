@php
    /*
     * One tag (TBL-01): its English name with its translation status
     * (STS-05), the slug and its posts. Edit is inline; the rest is in the
     * row menu (TBL-04). Merge into… is there, unavailable, until merging
     * tags is designed; Delete is an administrator's, and while posts carry
     * the tag its dialog says why it cannot go.
     */
    $id = 'rg-admin-tag-'.$row['id'];
@endphp

<div
    id="{{ $id }}"
    class="rg-admin-table__row"
    role="row"
    wire:key="tag-{{ $row['id'] }}"
    data-search="{{ $row['search'] }}"
    x-show="matches($el.dataset.search)"
>
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        {{-- A real link to the drawer, so it opens in a new tab too; a click opens it in place. --}}
        <a href="{{ $row['url'] }}" class="rg-admin-taxonomy__name" wire:click.prevent="edit({{ $row['id'] }})">{{ $row['name'] }}</a>
        @include('filament.pages.taxonomy.chips', ['chips' => $row['chips'], 'missing' => $row['missing']])
    </div>

    <div class="rg-admin-table__cell rg-admin-taxonomy__slug" role="cell" title="{{ $row['slug'] }}">{{ $row['slug'] }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($row['posts']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" wire:click="edit({{ $row['id'] }})">
            Edit<span class="rg-admin-sr-only"> {{ $row['name'] }}</span>
        </x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for '.$row['name']">
            <x-admin.ui.menu-item icon="merge" hint="Not available yet" disabled>Merge into…</x-admin.ui.menu-item>

            @if ($row['translate'] !== null)
                <x-admin.ui.menu-item icon="languages" :href="$row['translate']">Translate</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="languages" hint="Only administrators translate" disabled>Translate</x-admin.ui.menu-item>
            @endif

            <div class="rg-admin-menu__separator" role="separator"></div>

            @if ($canDelete)
                <x-admin.ui.menu-item
                    icon="trash-2"
                    tone="danger"
                    :hint="$row['posts'] > 0 ? 'In use · remove it from its posts first' : 'Unused'"
                    wire:click="askToDelete({{ $row['id'] }})"
                >Delete</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Only administrators delete tags" disabled>Delete</x-admin.ui.menu-item>
            @endif
        </x-admin.ui.row-menu>
    </div>
</div>
