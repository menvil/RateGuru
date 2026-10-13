@php
    /*
     * One comment (TBL-01): its text in two lines — a link to it under the
     * public post while it is visible there — with its id, and “Read-only”
     * once its author deleted it or its removal is final; the author; the
     * post it is on; the reports (STS-02); the state (STS-01) with its note;
     * when it was written. Hide or Restore is inline, the rest in the row
     * menu (TBL-04).
     */
    $id = 'rg-admin-comment-'.$row['id'];
@endphp

<div id="{{ $id }}" class="rg-admin-table__row rg-admin-comments__row" role="row" wire:key="comment-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        @if ($row['thread'] !== null)
            <a href="{{ $row['thread'] }}" class="rg-admin-comments__text rg-admin-comments__text--link" target="_blank" rel="noopener" title="Open under the public post">{{ $row['text'] }}</a>
        @else
            <div @class(['rg-admin-comments__text', 'rg-admin-comments__text--muted' => $row['muted']])>{{ $row['text'] }}</div>
        @endif
        <div class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-comments__meta">
            <span>#{{ $row['id'] }}</span>
            @if ($row['readOnly'] !== null)
                <span class="rg-admin-comments__read-only">· <x-admin.ui.icon name="lock" :size="12" />{{ $row['readOnly'] }}</span>
            @endif
        </div>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['author'] !== null)
            <div class="rg-admin-table__person">
                <span class="rg-admin-avatar" aria-hidden="true">{{ $row['author']['initials'] }}</span>
                @if ($row['author']['url'] !== null)
                    <a href="{{ $row['author']['url'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['author']['name'] }}</a>
                @else
                    <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $row['author']['name'] }}</span>
                @endif
            </div>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No author</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['post'] !== null)
            <div class="rg-admin-table__stack">
                <span class="rg-admin-comments__post rg-admin-table__ellipsis" title="{{ $row['post']['title'] }}">{{ $row['post']['title'] }}</span>
                <span class="rg-admin-table__meta rg-admin-table__meta--small">#{{ $row['post']['id'] }}</span>
            </div>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No post</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['reports'] > 0)
            <x-admin.ui.badge :tone="$row['reports'] >= 3 ? 'danger' : 'warning'" icon="flag">{{ number_format($row['reports']) }}<span class="rg-admin-sr-only"> {{ \Illuminate\Support\Str::plural('report', $row['reports']) }}</span></x-admin.ui.badge>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No reports</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <div class="rg-admin-badge-note">
            <x-admin.ui.badge :tone="$row['state']['tone']" :dot="$row['state']['dot']">{{ $row['state']['label'] }}</x-admin.ui.badge>
            @if ($row['state']['note'] !== null)
                <span class="rg-admin-badge-note__text">{{ $row['state']['note'] }}</span>
            @endif
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-comments__created" role="cell">{{ $row['created'] }}</div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        @if ($row['hide'])
            <x-admin.ui.button size="sm" wire:click="askToHide({{ $row['id'] }})">
                Hide<span class="rg-admin-sr-only"> comment #{{ $row['id'] }}</span>
            </x-admin.ui.button>
        @endif
        @if ($row['restore'])
            <x-admin.ui.button size="sm" wire:click="restore({{ $row['id'] }})" wire:loading.attr="disabled">
                Restore<span class="rg-admin-sr-only"> comment #{{ $row['id'] }}</span>
            </x-admin.ui.button>
        @endif

        @if ($row['final'])
            <span class="rg-admin-table__final" title="Removal finalized. No further actions.">
                <x-admin.ui.icon name="lock" :size="14" />Final
            </span>
        @elseif ($row['menu'])
            <x-admin.ui.row-menu :id="$id.'-actions'" :label="'More actions for comment #'.$row['id']">
                @if ($row['thread'] !== null)
                    <x-admin.ui.menu-item icon="arrow-up-right" :href="$row['thread']" target="_blank" rel="noopener">Open in thread</x-admin.ui.menu-item>
                @endif
                @if ($row['author']['url'] ?? null)
                    <x-admin.ui.menu-item icon="user" :href="$row['author']['url']">View author</x-admin.ui.menu-item>
                @endif
                @if ($row['postUrl'] !== null)
                    <x-admin.ui.menu-item icon="image" :href="$row['postUrl']">Open post in moderation</x-admin.ui.menu-item>
                @endif

                @if ($row['finalize'] === 'available')
                    <div class="rg-admin-menu__separator" role="separator"></div>
                    <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Irreversible" wire:click="askToFinalize({{ $row['id'] }})">Finalize removal</x-admin.ui.menu-item>
                @elseif ($row['finalize'] === 'hide first')
                    <div class="rg-admin-menu__separator" role="separator"></div>
                    <x-admin.ui.menu-item icon="trash-2" tone="danger" hint="Hide the comment first" disabled>Finalize removal</x-admin.ui.menu-item>
                @endif
            </x-admin.ui.row-menu>
        @endif
    </div>
</div>
