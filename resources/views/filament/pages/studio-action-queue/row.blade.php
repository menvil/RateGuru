@php
    /*
     * One action of the Action queue (TBL-01): its publication — a link to the
     * plan — and its id; what it does; its persona; when it is planned, when
     * it runs or ran; its state (STS-01) and why it stopped; Retry, Resume or
     * Cancel as its state allows, and Details, which opens in place: the
     * sentence of what it is, its attempts, its dates, what it depends on and
     * what it wrote.
     */
    $id = 'rg-admin-studio-action-'.$row['id'];
    $actor = $row['actor'];
@endphp

<div id="{{ $id }}" class="rg-admin-studio-queue__item" role="rowgroup" wire:key="studio-action-{{ $row['id'] }}" x-data="{ open: false }">
    <div @class(['rg-admin-table__row', 'rg-admin-studio-queue__row--failed' => $row['state']['tone'] === 'danger']) role="row">
        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
            <div class="rg-admin-table__stack">
                @if ($row['plan'] !== null)
                    <a href="{{ $row['plan']['url'] }}" class="rg-admin-table__name rg-admin-table__name--link rg-admin-table__ellipsis">{{ $row['plan']['title'] }}</a>
                    <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-mono">#{{ $row['plan']['id'] }} · action #{{ $row['id'] }}</span>
                @else
                    <span class="rg-admin-table__none">A deleted plan</span>
                    <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-mono">action #{{ $row['id'] }}</span>
                @endif
            </div>
        </div>

        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
            <div class="rg-admin-studio__plan-action">
                <x-admin.ui.icon :name="$row['icon']" :size="14" class="rg-admin-studio__plan-action-icon" />
                <div class="rg-admin-table__stack">
                    <span class="rg-admin-table__ellipsis">{{ $row['what'] }}</span>
                    <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['kind'] }}{{ $row['parent'] !== null ? ' · after #'.$row['parent'] : '' }}</span>
                </div>
            </div>
        </div>

        <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
            <div class="rg-admin-table__person">
                <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                    @if ($actor['avatar'] !== null)
                        <img src="{{ $actor['avatar'] }}" alt="" class="rg-admin-avatar__image" loading="lazy" />
                    @elseif ($actor['initials'] !== '')
                        {{ $actor['initials'] }}
                    @else
                        <x-admin.ui.icon name="user" :size="14" />
                    @endif
                </span>
                <div class="rg-admin-table__stack">
                    <span @class(['rg-admin-table__name', 'rg-admin-table__ellipsis', 'rg-admin-table__none' => $actor['username'] === null])>{{ $actor['username'] !== null ? '@'.$actor['username'] : $actor['name'] }}</span>
                    @if ($actor['username'] !== null && mb_strtolower($actor['name']) !== mb_strtolower($actor['username']))
                        <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $actor['name'] }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="rg-admin-table__cell" role="cell">
            <div class="rg-admin-table__stack">
                <span @class(['rg-admin-studio__plan-time', 'rg-admin-studio__plan-time--due' => $row['overdue']])>{{ $row['planned'] }}</span>
                @if ($row['overdue'])
                    <span class="rg-admin-studio__plan-overdue">Overdue</span>
                @endif
            </div>
        </div>

        <div class="rg-admin-table__cell" role="cell">
            <div class="rg-admin-table__stack">
                <span @class(['rg-admin-studio__plan-time', 'rg-admin-studio__plan-time--due' => $row['late']])>{{ $row['runs'] }}</span>
                @if ($row['runsNote'] !== null)
                    <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $row['runsNote'] }}</span>
                @endif
            </div>
        </div>

        <div class="rg-admin-table__cell" role="cell">
            <div class="rg-admin-table__stack">
                <span><x-admin.ui.badge :tone="$row['state']['tone']" :dot="$row['state']['dot']">{{ $row['state']['label'] }}</x-admin.ui.badge></span>
                @if ($row['code'] !== null && $row['state']['label'] !== 'Done')
                    <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-mono">{{ $row['code'] }}</span>
                @endif
            </div>
        </div>

        <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
            @if ($row['canRetry'])
                <x-admin.ui.button size="sm" icon="rotate-ccw" wire:click="retry({{ $row['id'] }})" wire:loading.attr="disabled">Retry<span class="rg-admin-sr-only"> action #{{ $row['id'] }}</span></x-admin.ui.button>
            @endif
            @if ($row['canResume'])
                <x-admin.ui.button size="sm" icon="play" wire:click="resume({{ $row['id'] }})" wire:loading.attr="disabled">Resume<span class="rg-admin-sr-only"> action #{{ $row['id'] }}</span></x-admin.ui.button>
            @endif
            @if ($row['canCancel'])
                <x-admin.ui.button size="sm" wire:click="askToCancel({{ $row['id'] }})">Cancel<span class="rg-admin-sr-only"> action #{{ $row['id'] }}</span></x-admin.ui.button>
            @endif
            <x-admin.ui.icon-button
                icon="chevron-down"
                :label="'Details of action #'.$row['id']"
                variant="ghost"
                size="sm"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open ? 'true' : 'false'"
                aria-controls="{{ $id }}-details"
                x-bind:class="open && 'rg-admin-studio-queue__toggle--open'"
            />
        </div>
    </div>

    <div id="{{ $id }}-details" class="rg-admin-studio-queue__details" role="row" x-show="open" x-cloak>
        <div class="rg-admin-studio-queue__details-body" role="cell">
            @if ($row['reason'] !== null)
                <p class="rg-admin-studio-queue__reason">{{ $row['reason'] }}</p>
            @endif
            <dl class="rg-admin-studio-queue__facts">
                <div><dt>Planned</dt><dd>{{ $row['planned'] }}</dd></div>
                <div><dt>May run from</dt><dd>{{ $row['runAfter'] }}</dd></div>
                <div><dt>Finished</dt><dd>{{ $row['finished'] ?? 'Not yet' }}</dd></div>
                <div><dt>Attempts</dt><dd>{{ $row['attempts'] }}{{ $row['deferrals'] > 0 ? ' · '.$row['deferrals'].' held back by a limit' : '' }}</dd></div>
                @if ($row['dispatched'] !== null)
                    <div><dt>Last sent</dt><dd>{{ $row['dispatched'] }}</dd></div>
                @endif
                <div><dt>Depends on</dt><dd>@if ($row['parent'] !== null)<a href="{{ \App\Filament\Pages\StudioActionQueue::getUrl(['q' => '#'.$row['parent']]) }}" class="rg-admin-link">Action #{{ $row['parent'] }}</a>@else Nothing: it starts the plan @endif</dd></div>
            </dl>
            <div class="rg-admin-studio-queue__links">
                @if ($row['plan'] !== null)
                    <x-admin.ui.button size="sm" icon="panel-right-open" :href="$row['plan']['url']">Open plan</x-admin.ui.button>
                @endif
                @foreach ($row['links'] as $link)
                    <x-admin.ui.button size="sm" icon="arrow-up-right" :href="$link['url']" target="_blank" rel="noopener">{{ $link['label'] }}</x-admin.ui.button>
                @endforeach
                @if ($row['links'] === [] && $row['state']['label'] === 'Done')
                    <span class="rg-admin-table__meta rg-admin-table__meta--small">What it wrote isn’t on the site now.</span>
                @endif
            </div>
        </div>
    </div>
</div>
