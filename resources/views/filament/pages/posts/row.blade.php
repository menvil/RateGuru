@php
    /*
     * One post (TBL-01): its selection box, the thumbnail that opens the
     * original, the title — a link to the public post while it is published —
     * with its id and category, the author with whether they publish at once
     * and how many posts they have, the status (STS-01) with its note, the
     * reports (STS-02), when it came in and, while pending, how long it has
     * waited. The status's one or two actions are inline; the rest is in the
     * row menu (TBL-04). A finalized removal has nothing left to do.
     */
    $id = 'rg-admin-post-'.$row['id'];
@endphp

<div
    id="{{ $id }}"
    @class(['rg-admin-table__row', 'rg-admin-posts__row', 'rg-admin-table__row--selected' => $row['selected']])
    role="row"
    wire:key="post-{{ $row['id'] }}"
>
    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.checkbox :label="'Select '.$row['title']" hide-label :id="$id.'-select'" :value="$row['id']" :checked="$row['selected']" wire:model.live="selected" />
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['hasImage'])
            <button type="button" class="rg-admin-table__thumb rg-admin-posts__thumb" aria-label="Open the original image of {{ $row['title'] }}" title="Open original image" wire:click="view({{ $row['id'] }})">
                @if ($row['thumbnail'] !== null)
                    <img src="{{ $row['thumbnail'] }}" alt="" loading="lazy" class="rg-admin-posts__thumb-image" />
                @else
                    <x-admin.ui.icon name="image" :size="18" />
                @endif
            </button>
        @else
            <span class="rg-admin-table__thumb" title="No image"><x-admin.ui.icon name="image" :size="18" /></span>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        @if ($row['url'] !== null)
            <a href="{{ $row['url'] }}" class="rg-admin-link rg-admin-link--external rg-admin-posts__title" target="_blank" rel="noopener" title="Open public post">
                <span class="rg-admin-posts__title-text">{{ $row['title'] }}</span>
                <x-admin.ui.icon name="arrow-up-right" :size="14" />
            </a>
        @else
            <div @class(['rg-admin-table__primary', 'rg-admin-posts__title--muted' => $row['muted']])>{{ $row['title'] }}</div>
        @endif
        <div class="rg-admin-table__meta rg-admin-table__ellipsis" title="{{ $row['meta'] }}">{{ $row['meta'] }}</div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['author'] !== null)
            <div class="rg-admin-table__person">
                <span class="rg-admin-avatar" aria-hidden="true">{{ $row['author']['initials'] }}</span>
                <div class="rg-admin-table__stack">
                    @if ($row['author']['url'] !== null)
                        <a href="{{ $row['author']['url'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['author']['name'] }}</a>
                    @else
                        <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $row['author']['name'] }}</span>
                    @endif
                    <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis" title="{{ $row['author']['meta'] }}">{{ $row['author']['meta'] }}</span>
                </div>
            </div>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No author</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-badge-note">
            <x-admin.ui.badge :tone="$row['status']['tone']" :dot="$row['status']['dot']">{{ $row['status']['label'] }}</x-admin.ui.badge>
            @if (filled($row['status']['note']))
                <span class="rg-admin-badge-note__text rg-admin-table__ellipsis" title="{{ $row['status']['note'] }}">{{ $row['status']['note'] }}</span>
            @endif
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['reports'] > 0)
            <x-admin.ui.badge :tone="$row['reports'] >= 3 ? 'danger' : 'warning'" icon="flag">{{ number_format($row['reports']) }}<span class="rg-admin-sr-only"> {{ \Illuminate\Support\Str::plural('report', $row['reports']) }}</span></x-admin.ui.badge>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No reports</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-table__nowrap">{{ $row['created'] }}</div>
        @if ($row['waiting'] !== null)
            <div @class(['rg-admin-table__meta', 'rg-admin-table__meta--small', 'rg-admin-table__nowrap', 'rg-admin-table__meta--due' => $row['due']])>{{ $row['waiting'] }}</div>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        @if ($row['approve'])
            <x-admin.ui.button size="sm" wire:click="approve({{ $row['id'] }})" wire:loading.attr="disabled">
                Approve<span class="rg-admin-sr-only"> {{ $row['title'] }}</span>
            </x-admin.ui.button>
        @endif
        @if ($row['reject'])
            <x-admin.ui.button size="sm" wire:click="askToReject({{ $row['id'] }})">
                Reject<span class="rg-admin-sr-only"> {{ $row['title'] }}</span>
            </x-admin.ui.button>
        @endif
        @if ($row['hide'])
            <x-admin.ui.button size="sm" wire:click="askToHide({{ $row['id'] }})">
                Hide<span class="rg-admin-sr-only"> {{ $row['title'] }}</span>
            </x-admin.ui.button>
        @endif
        @if ($row['restore'])
            <x-admin.ui.button size="sm" wire:click="restore({{ $row['id'] }})" wire:loading.attr="disabled">
                Restore<span class="rg-admin-sr-only"> {{ $row['title'] }}</span>
            </x-admin.ui.button>
        @endif

        @if ($row['final'])
            <span class="rg-admin-table__final" title="Removal finalized. No further actions.">
                <x-admin.ui.icon name="lock" :size="14" />Final
            </span>
        @elseif ($row['menu'])
            <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for '.$row['title']">
                @if ($row['url'] !== null)
                    <x-admin.ui.menu-item icon="arrow-up-right" :href="$row['url']" target="_blank" rel="noopener">Open public post</x-admin.ui.menu-item>
                @endif
                @if ($row['hasImage'])
                    <x-admin.ui.menu-item icon="image" wire:click="view({{ $row['id'] }})">Open original image</x-admin.ui.menu-item>
                @endif
                @if ($row['author']['url'] ?? null)
                    <x-admin.ui.menu-item icon="user" :href="$row['author']['url']">View author</x-admin.ui.menu-item>
                @endif

                @if ($row['finalize'] === 'available')
                    <div class="rg-admin-menu__separator" role="separator"></div>
                    <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Irreversible" wire:click="askToFinalize({{ $row['id'] }})">Finalize removal</x-admin.ui.menu-item>
                @elseif ($row['finalize'] === 'hide first')
                    <div class="rg-admin-menu__separator" role="separator"></div>
                    <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Hide the post first" disabled>Finalize removal</x-admin.ui.menu-item>
                @endif
            </x-admin.ui.row-menu>
        @endif
    </div>
</div>
