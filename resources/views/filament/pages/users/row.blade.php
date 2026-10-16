@php
    /*
     * One person (TBL-01): their avatar, username — a link to their page
     * for whoever may edit it — name and when they joined; the email
     * address; the role, staff as an outline badge; the status (STS-01);
     * whether they are trusted; their posts; the reports on their content
     * (STS-02). Edit is inline; the moderation actions are in the row menu
     * (TBL-04), each unavailable one with its reason.
     */
    $id = 'rg-admin-user-'.$row['id'];
    $options = $row['options'];
@endphp

<div id="{{ $id }}" class="rg-admin-table__row rg-admin-users__row" role="row" wire:key="user-{{ $row['id'] }}">
    <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
        <div class="rg-admin-table__person">
            <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">{{ $row['initials'] }}</span>
            <div class="rg-admin-table__stack">
                @if ($row['editUrl'] !== null)
                    <a href="{{ $row['editUrl'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-users__username rg-admin-table__ellipsis">{{ $row['username'] }}</a>
                @else
                    <span class="rg-admin-table__name rg-admin-users__username rg-admin-table__ellipsis">{{ $row['username'] }}</span>
                @endif
                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $row['meta'] }}</span>
            </div>
        </div>
    </div>

    <div class="rg-admin-table__cell rg-admin-users__email" role="cell">
        <span class="rg-admin-table__ellipsis" title="{{ $row['email'] }}">{{ $row['email'] }}</span>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['staff'])
            <x-admin.ui.badge tone="outline">{{ $row['role'] }}</x-admin.ui.badge>
        @else
            <span class="rg-admin-users__role">{{ $row['role'] }}</span>
        @endif
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <x-admin.ui.badge :tone="$row['status']['tone']" :dot="$row['status']['dot']">{{ $row['status']['label'] }}</x-admin.ui.badge>
    </div>

    <div class="rg-admin-table__cell" role="cell">
        <span @class(['rg-admin-users__trust', 'rg-admin-users__trust--trusted' => $row['trust'] === 'Trusted'])>
            <span class="rg-admin-users__trust-bar" aria-hidden="true"><span></span><span></span></span>
            {{ $row['trust'] }}
        </span>
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="cell">{{ number_format($row['posts']) }}</div>

    <div class="rg-admin-table__cell" role="cell">
        @if ($row['reports'] > 0)
            <x-admin.ui.badge :tone="$row['reports'] >= 3 ? 'danger' : 'warning'" icon="flag">{{ number_format($row['reports']) }}<span class="rg-admin-sr-only"> {{ \Illuminate\Support\Str::plural('report', $row['reports']) }} on their content</span></x-admin.ui.badge>
        @else
            <span class="rg-admin-table__none"><span aria-hidden="true">—</span><span class="rg-admin-sr-only">No reports</span></span>
        @endif
    </div>

    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
        @if ($row['editUrl'] !== null)
            <x-admin.ui.button size="sm" :href="$row['editUrl']">Edit<span class="rg-admin-sr-only"> {{ $row['username'] }}</span></x-admin.ui.button>
        @else
            <x-admin.ui.button size="sm" disabled :title="$row['deleted'] ? 'The account was deleted' : 'Not yours to edit'">Edit<span class="rg-admin-sr-only"> {{ $row['username'] }}</span></x-admin.ui.button>
        @endif

        @unless ($row['deleted'])
            <x-admin.ui.row-menu :id="$id.'-actions'" :label="'Moderation actions for '.$row['username']">
                <p class="rg-admin-menu__caption">{{ $row['username'] }} · {{ $row['status']['label'] }} · {{ $row['trust'] }}</p>
                @if ($row['editUrl'] !== null)
                    <x-admin.ui.menu-item icon="pencil" :href="$row['editUrl']">Edit user</x-admin.ui.menu-item>
                @endif
                @if ($row['postsUrl'] !== null)
                    <x-admin.ui.menu-item icon="image" :href="$row['postsUrl']">View posts</x-admin.ui.menu-item>
                @endif

                <div class="rg-admin-menu__separator" role="separator"></div>

                <x-admin.ui.menu-item icon="shield-check" :hint="$options['trust']['hint']" :disabled="! $options['trust']['available']" wire:click="askToSanction('trust', {{ $row['id'] }})">Mark trusted</x-admin.ui.menu-item>
                @if ($options['restore'])
                    <x-admin.ui.menu-item icon="rotate-ccw" hint="Back to Active" wire:click="restoreAccess({{ $row['id'] }})">Restore access</x-admin.ui.menu-item>
                @endif
                @if ($options['limit']['shown'])
                    <x-admin.ui.menu-item icon="shield-alert" :hint="$options['limit']['hint']" :disabled="! $options['limit']['available']" wire:click="askToSanction('limit', {{ $row['id'] }})">Limit</x-admin.ui.menu-item>
                @endif
                @if ($options['shadowban']['shown'])
                    <x-admin.ui.menu-item icon="eye-off" tone="danger" :hint="$options['shadowban']['hint']" :disabled="! $options['shadowban']['available']" wire:click="askToSanction('shadowban', {{ $row['id'] }})">Shadowban</x-admin.ui.menu-item>
                @endif
                @if ($options['ban']['shown'])
                    <x-admin.ui.menu-item icon="ban" tone="danger" :hint="$options['ban']['hint']" :disabled="! $options['ban']['available']" wire:click="askToSanction('ban', {{ $row['id'] }})">Ban</x-admin.ui.menu-item>
                @endif
            </x-admin.ui.row-menu>
        @endunless
    </div>
</div>
