@php
    /*
     * One publication plan (TBL-01): its title — a link to its editor — and
     * id; its author, or that none is chosen yet, or that the account was
     * deleted, never what it was called; its photo at thumbnail size; when it
     * is to be published, in words that promise nothing a draft does not do;
     * its state (STS-01); when it was last saved. A draft has Edit inline and
     * Delete draft in the row menu (TBL-04), which asks about the version
     * this row shows; a plan past its draft has neither, and the menu says
     * why.
     */
    $id = 'rg-admin-studio-plan-'.$row['id'];
    $author = $row['author'];
@endphp

<div id="{{ $id }}" class="rg-admin-table__row" role="row" wire:key="studio-plan-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div class="rg-admin-table__stack">
            <a href="{{ $row['editUrl'] }}" @class(['rg-admin-table__name', 'rg-admin-table__name--link', 'rg-admin-table__ellipsis', 'rg-admin-studio-plans__untitled' => $row['untitled']])>{{ $row['title'] }}</a>
            <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-mono">#{{ $row['id'] }}</span>
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        @if ($author === null)
            <span class="rg-admin-table__none">No author selected</span>
        @else
            <div class="rg-admin-table__person">
                <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                    @if ($author['avatar'] !== null)
                        <img src="{{ $author['avatar'] }}" alt="" class="rg-admin-avatar__image" loading="lazy" />
                    @elseif ($author['initials'] !== '')
                        {{ $author['initials'] }}
                    @else
                        <x-admin.ui.icon name="user" :size="14" />
                    @endif
                </span>
                <div class="rg-admin-table__stack">
                    <span @class(['rg-admin-table__name', 'rg-admin-table__ellipsis', 'rg-admin-table__none' => $author['problem'] === 'deleted'])>{{ $author['name'] }}</span>
                    @if ($author['username'] !== null)
                        <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ '@'.$author['username'] }}@if ($author['problem'] === 'restricted') · {{ $author['status']['label'] }}@endif</span>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['hasImage'])
            <span class="rg-admin-table__thumb rg-admin-studio-plans__thumb">
                @if ($row['thumbnail'] !== null)
                    <img src="{{ $row['thumbnail'] }}" alt="Photo of {{ $row['title'] }}" loading="lazy" class="rg-admin-studio-plans__thumb-image" />
                @else
                    <x-admin.ui.icon name="image" :size="18" label="Photo of {{ $row['title'] }}" />
                @endif
            </span>
        @else
            <span class="rg-admin-table__thumb" title="No image"><x-admin.ui.icon name="image" :size="18" label="No image" /></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <span @class(['rg-admin-table__none' => $row['when'] === 'Date not selected'])>{{ $row['when'] }}</span>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['state']['tone']" :dot="$row['state']['dot']">{{ $row['state']['label'] }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <span class="rg-admin-table__meta">{{ $row['updated'] }}</span>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        @if ($row['draft'])
            <x-admin.ui.button size="sm" :href="$row['editUrl']">Edit<span class="rg-admin-sr-only"> {{ $row['title'] }}</span></x-admin.ui.button>
        @else
            <x-admin.ui.button size="sm" :href="$row['editUrl']">Open<span class="rg-admin-sr-only"> {{ $row['title'] }}</span></x-admin.ui.button>
        @endif

        <x-admin.ui.row-menu :id="$id.'-actions'" :label="'Actions for '.$row['title']">
            <p class="rg-admin-menu__caption">{{ $row['title'] }} · {{ $row['state']['label'] }}</p>
            @if ($row['draft'])
                <x-admin.ui.menu-item icon="pencil" :href="$row['editUrl']">Edit draft</x-admin.ui.menu-item>
                <x-admin.ui.menu-item icon="trash-2" tone="danger" wire:click="askToDelete({{ $row['id'] }}, {{ $row['version'] }})">Delete draft</x-admin.ui.menu-item>
            @else
                <x-admin.ui.menu-item icon="pencil" :disabled="true" hint="Only a draft can be edited">Edit draft</x-admin.ui.menu-item>
                <x-admin.ui.menu-item icon="trash-2" tone="danger" :disabled="true" hint="Only a draft can be deleted">Delete draft</x-admin.ui.menu-item>
            @endif
        </x-admin.ui.row-menu>
    </div>
</div>
