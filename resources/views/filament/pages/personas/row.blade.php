@php
    /*
     * One Persona (TBL-01): its avatar or initials, its display name — which
     * opens its editor in the drawer — and username; its short description,
     * with its speech style and emoji under it; its status (STS-01); its
     * engagement and how many replies a day it may write. Edit is inline; the
     * row menu (TBL-04) leads to its editor, its public profile and its
     * moderation in Users. Each link is an address of its own too, so it can
     * open in a new tab.
     */
    $id = 'rg-admin-persona-'.$row['id'];
    $character = trim(implode(' · ', array_filter([$row['style'], 'Emoji: '.$row['emoji']])));
@endphp

<div id="{{ $id }}" class="rg-admin-table__row rg-admin-personas__row" role="row" wire:key="persona-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div class="rg-admin-table__person">
            <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                @if ($row['avatar'] !== null)
                    <img src="{{ $row['avatar'] }}" alt="" class="rg-admin-avatar__image" loading="lazy" />
                @else
                    {{ $row['initials'] }}
                @endif
            </span>
            <div class="rg-admin-table__stack">
                <a href="{{ $row['editUrl'] }}" wire:click.prevent="editPersona({{ $row['id'] }})" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['name'] }}</a>
                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ '@'.$row['username'] }}</span>
            </div>
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div class="rg-admin-table__stack">
            @if ($row['short'] !== '')
                <span class="rg-admin-personas__short">{{ $row['short'] }}</span>
            @else
                <span class="rg-admin-table__none">No description yet</span>
            @endif
            <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $character }}</span>
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['status']['tone']" :dot="$row['status']['dot']">{{ $row['status']['label'] }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell" role="cell">{{ $row['engagement'] }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($row['replies']) }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        <x-admin.ui.button size="sm" :href="$row['editUrl']" wire:click.prevent="editPersona({{ $row['id'] }})">Edit<span class="rg-admin-sr-only"> {{ $row['name'] }}</span></x-admin.ui.button>

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'Actions for '.$row['name']">
            <p class="rg-admin-menu__caption">{{ $row['name'] }} · {{ $row['status']['label'] }}</p>
            <x-admin.ui.menu-item icon="pencil" :href="$row['editUrl']" wire:click.prevent="editPersona({{ $row['id'] }})">Edit persona</x-admin.ui.menu-item>
            <x-admin.ui.menu-item icon="arrow-up-right" :href="$row['profileUrl']" target="_blank" rel="noopener">View public profile</x-admin.ui.menu-item>
            @if ($row['usersUrl'] !== null)
                <x-admin.ui.menu-item icon="users" :href="$row['usersUrl']" hint="Limit, ban or restore access">Moderate in Users</x-admin.ui.menu-item>
            @endif
        </x-admin.ui.row-menu>
    </div>
</div>
