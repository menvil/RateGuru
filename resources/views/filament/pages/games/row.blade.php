@php
    /*
     * One game (TBL-01): its icon and public title — a link to its editor —
     * with its internal name, mechanic and address; its newest version and
     * where that version's traffic stands; its status (STS-01); its plays,
     * completion and sign-ups of the last 30 days; when it was last saved;
     * and the row menu (TBL-04) into its editor's tabs. Its status is
     * changed in the editor.
     */
    $id = 'rg-admin-game-'.$row['id'];
@endphp

<div id="{{ $id }}" class="rg-admin-table__row" role="row" wire:key="game-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded rg-admin-games__identity" role="cell">
        <span class="rg-admin-games__glyph" title="{{ $row['mechanic'] }}"><x-admin.ui.icon :name="$row['icon']" :size="18" /></span>
        <div class="rg-admin-table__stack">
            <a href="{{ $row['editUrl'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['title'] }}</a>
            <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $row['internal'] }} · {{ $row['mechanic'] }} · <span class="rg-admin-mono">/games/{{ $row['slug'] }}</span></span>
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['version'] === null)
            <span class="rg-admin-table__none">Not published yet</span>
        @else
            <div class="rg-admin-table__stack">
                <span class="rg-admin-table__name">{{ $row['version']['label'] }}</span>
                <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['version']['published'] }} · {{ $row['version']['state']['label'] }}</span>
            </div>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['status']['tone']" :dot="$row['status']['dot']">{{ $row['status']['label'] }}</x-admin.ui.badge>
    </div>

    @foreach (['plays', 'completion', 'signups'] as $figure)
        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">
            @if ($row[$figure] === null)
                <span class="rg-admin-table__none">—</span>
            @else
                {{ $row[$figure] }}
            @endif
        </div>
    @endforeach

    <div class="rg-admin-table__cell" role="cell">
        <span class="rg-admin-table__meta">{{ $row['updated'] }}</span>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'Actions for '.$row['title']">
            @foreach ($row['links'] as $link)
                <x-admin.ui.menu-item :icon="$link['icon']" :href="$link['url']">{{ $link['label'] }}</x-admin.ui.menu-item>
            @endforeach
        </x-admin.ui.row-menu>
    </div>
</div>
