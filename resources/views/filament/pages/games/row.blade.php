@php
    /*
     * One game (TBL-01): its public title — a link to its editor — with its
     * internal name and address; its mechanic; its newest version and where
     * that version's traffic stands; its status (STS-01); when it was last
     * saved; Edit inline and the status moves in the row menu (TBL-04), each
     * asked first.
     */
    $id = 'rg-admin-game-'.$row['id'];
    $moves = match ($row['statusValue']) {
        'draft' => ['live' => ['Go live', 'play'], 'archived' => ['Archive', 'archive']],
        'live' => ['paused' => ['Pause', 'pause'], 'archived' => ['Archive', 'archive']],
        'paused' => ['live' => ['Resume', 'play'], 'archived' => ['Archive', 'archive']],
        default => [$row['published'] ? 'paused' : 'draft' => ['Restore', 'archive-restore']],
    };
@endphp

<div id="{{ $id }}" class="rg-admin-table__row" role="row" wire:key="game-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div class="rg-admin-table__stack">
            <a href="{{ $row['editUrl'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['title'] }}</a>
            <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $row['internal'] }} · <span class="rg-admin-mono">/games/{{ $row['slug'] }}</span></span>
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <span class="rg-admin-table__meta">{{ $row['mechanic'] }}</span>
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

    <div class="rg-admin-table__cell" role="cell">
        <span class="rg-admin-table__meta">{{ $row['updated'] }}</span>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" :href="$row['editUrl']">Edit<span class="rg-admin-sr-only"> {{ $row['title'] }}</span></x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'Actions for '.$row['title']">
            <p class="rg-admin-menu__caption">{{ $row['title'] }} · {{ $row['status']['label'] }}</p>
            <x-admin.ui.menu-item icon="pencil" :href="$row['editUrl']">Edit</x-admin.ui.menu-item>
            @foreach ($moves as $target => [$label, $icon])
                <x-admin.ui.menu-item :icon="$icon" :tone="$target === 'archived' ? 'danger' : 'default'" wire:click="askStatus({{ $row['id'] }}, '{{ $target }}')">{{ $label }}</x-admin.ui.menu-item>
            @endforeach
        </x-admin.ui.row-menu>
    </div>
</div>
