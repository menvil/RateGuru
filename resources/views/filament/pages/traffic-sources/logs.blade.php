{{--
    Logs, as the reference draws them: postbacks — when, which source, the
    milestone and how the account came where it did with its whole path,
    the click, the exact request and the network's answer, with Resend —
    milestones, clicks and blocking, newest first, fifty to a page. A click,
    a milestone and a postback name their visitor by its journey ID, a link
    to its journey in Growth → Analytics (journey-link), and a postback's
    path ends with it too.
--}}
@php
    $logs = ['postbacks' => 'Postbacks'.($failing > 0 ? ' · '.$failing.' failing' : ''), 'milestones' => 'Milestones', 'clicks' => 'Clicks', 'blocks' => 'Blocks'];
    $when = fn ($at): array => [$at->format('H:i'), $at->isToday() ? 'Today' : ($at->isYesterday() ? 'Yesterday' : $at->format('j M'))];
@endphp

<section class="rg-admin-traffic__panel" data-testid="traffic-log-{{ $log }}">
    <header class="rg-admin-traffic__perf-head">
        <div class="rg-admin-traffic__perf-tools">
            <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Log">
                @foreach ($logs as $key => $label)
                    <button type="button" role="radio" aria-checked="{{ $log === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => $log === $key]) wire:click="$set('log', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            @if ($log === 'postbacks')
                <div class="rg-admin-traffic__views" role="radiogroup" aria-label="Which postbacks">
                    @foreach (['all' => 'All', 'failed' => 'Failed only'] as $key => $label)
                        <button type="button" role="radio" aria-checked="{{ $logFilter === $key ? 'true' : 'false' }}" @class(['rg-admin-traffic__view', 'rg-admin-traffic__view--on' => $logFilter === $key]) wire:click="$set('logFilter', '{{ $key }}')">{{ $label }}</button>
                    @endforeach
                </div>
            @endif
        </div>
        @if ($log === 'postbacks')
            <span class="rg-admin-traffic__note">{{ $failing > 0 ? $failing.' failing · ' : '' }}retried automatically {{ $tries }} times over 12 hours</span>
        @endif
    </header>

    @if ($logPage->items() === [] && $pageNumber > 1)
        {{-- A later page an address kept from before can open after its entries have gone: the newest are a click away. --}}
        <x-admin.ui.empty-state icon="list" title="Nothing on this page">
            This page is past the oldest entry.
            <x-slot:action>
                <x-admin.ui.button size="sm" wire:click="goToPage(1)">Back to the newest</x-admin.ui.button>
            </x-slot:action>
        </x-admin.ui.empty-state>
    @elseif ($logPage->items() === [])
        <x-admin.ui.empty-state icon="list" title="Nothing in this log yet">{{ $log === 'postbacks' ? 'Postbacks show here once a source has a rule and its clicks reach a milestone.' : 'Entries show here as they happen.' }}</x-admin.ui.empty-state>
    @else
        <div class="rg-admin-traffic__perf rg-admin-table__scroll">
            <div @class(['rg-admin-traffic__perf-grid rg-admin-traffic__perf-grid--log', 'rg-admin-traffic__perf-grid--wide-log' => in_array($log, ['postbacks', 'clicks'], true)]) role="table" aria-label="{{ $logs[$log] }}">
                @switch ($log)
                    @case('postbacks')
                        <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--postbacks rg-admin-traffic__perf-row--head" role="row">
                            @foreach (['Time', 'Source', 'Visitor', 'Milestone', 'Click ID', 'Request', 'Result', ''] as $heading)<span role="columnheader">{{ $heading }}</span>@endforeach
                        </div>
                        @foreach ($logRows as $row)
                            @php([$time, $day] = $when($row['at']))
                            <div @class(['rg-admin-traffic__log-line rg-admin-traffic__log-line--postbacks', 'rg-admin-traffic__log-line--failed' => $row['status']->value === 'failed']) role="row" wire:key="postback-{{ $row['id'] }}" x-data="{ path: false }">
                                <span role="cell" class="rg-admin-traffic__perf-label"><strong>{{ $time }}</strong><span class="rg-admin-traffic__note">{{ $day }}</span></span>
                                <span role="cell">{{ $labels['source_id'][(string) $row['source']] ?? '' }}</span>
                                <span role="cell">@if ($row['visitor'] !== null)@include('filament.pages.traffic-sources.journey-link', ['journey' => $row['visitor']])@else—@endif</span>
                                <span role="cell" class="rg-admin-traffic__perf-label">
                                    <span>{{ $row['milestone'] }}</span>
                                    @if ($row['how'] !== null)<span class="rg-admin-traffic__note">{{ $row['how'] }}</span>@endif
                                    @if ($row['path'] !== [])
                                        <button type="button" class="rg-admin-traffic__text-button" x-on:click="path = ! path"><span x-text="path ? 'Hide the path' : 'Show the path'">Show the path</span></button>
                                        <ol class="rg-admin-traffic__path" x-show="path" x-cloak>
                                            @foreach ($row['path'] as $mark)
                                                <li><span>{{ $mark['label'] }}</span><span class="rg-admin-traffic__note">{{ $mark['at']->format('j M H:i') }}</span></li>
                                            @endforeach
                                            @if ($row['visitor'] !== null)
                                                <li><span>Journey</span>@include('filament.pages.traffic-sources.journey-link', ['journey' => $row['visitor']])</li>
                                            @endif
                                        </ol>
                                    @endif
                                </span>
                                <span role="cell" class="rg-admin-traffic__mono">{{ $row['clickId'] !== '' ? $row['clickId'] : '—' }}</span>
                                <span role="cell" class="rg-admin-traffic__mono rg-admin-traffic__log-request" title="{{ $row['request'] }}">{{ $row['request'] }}</span>
                                <span role="cell" class="rg-admin-traffic__perf-label">
                                    <x-admin.ui.badge :tone="match ($row['status']->value) { 'delivered' => 'success', 'failed', 'expired' => 'danger', 'pending', 'sending' => 'warning', default => 'neutral' }">{{ $row['http'] !== null ? 'HTTP '.$row['http'] : ucfirst(str_replace('_', ' ', $row['outcome']->value ?? $row['status']->value)) }}</x-admin.ui.badge>
                                    <span class="rg-admin-traffic__note">{{ $row['attempt'] }}</span>
                                </span>
                                <span role="cell">@if ($row['resendable'])<x-admin.ui.button size="sm" icon="refresh-cw" wire:click="resend({{ $row['id'] }})">Resend</x-admin.ui.button>@endif</span>
                            </div>
                        @endforeach
                        @break
                    @case('milestones')
                        <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--milestones rg-admin-traffic__perf-row--head" role="row">
                            @foreach (['Time', 'Source', 'Visitor', 'Milestone', 'Worth', 'Postback'] as $heading)<span role="columnheader">{{ $heading }}</span>@endforeach
                        </div>
                        @foreach ($logPage->items() as $row)
                            @php([$time, $day] = $when($row->occurred_at))
                            <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--milestones" role="row" wire:key="milestone-{{ $row->id }}">
                                <span role="cell" class="rg-admin-traffic__perf-label"><strong>{{ $time }}</strong><span class="rg-admin-traffic__note">{{ $day }}</span></span>
                                <span role="cell">{{ $labels['source_id'][(string) $row->source_id] ?? '' }}</span>
                                <span role="cell">@if (($visitors[$row->id] ?? null) !== null)@include('filament.pages.traffic-sources.journey-link', ['journey' => $visitors[$row->id]])@else—@endif</span>
                                <span role="cell">{{ $row->event === \App\Enums\TrafficEvent::Goal ? 'Flow goal reached'.($row->goal !== null ? ' · '.str_replace('_', ' ', is_string($row->goal) ? $row->goal : $row->goal->value) : '') : $row->event->label() }}</span>
                                <span role="cell" class="rg-admin-traffic__perf-label"><span>{{ \App\Support\Traffic\Money\TrafficMoney::fine($row->value) }}</span><span class="rg-admin-traffic__note">{{ $row->value_basis->label() }}</span></span>
                                <span role="cell" class="rg-admin-traffic__note">{{ $row->postback_skip?->label() ?? 'Sent to the network' }}</span>
                            </div>
                        @endforeach
                        @break
                    @case('blocks')
                        <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--blocks rg-admin-traffic__perf-row--head" role="row">
                            @foreach (['Time', 'Source', 'What', 'How', 'Result'] as $heading)<span role="columnheader">{{ $heading }}</span>@endforeach
                        </div>
                        @foreach ($logPage->items() as $row)
                            @php([$time, $day] = $when($row->occurred_at))
                            <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--blocks" role="row" wire:key="block-{{ $row->id }}">
                                <span role="cell" class="rg-admin-traffic__perf-label"><strong>{{ $time }}</strong><span class="rg-admin-traffic__note">{{ $day }}</span></span>
                                <span role="cell">{{ $labels['source_id'][(string) $row->source_id] ?? '' }}</span>
                                <span role="cell" class="rg-admin-traffic__perf-label"><span>{{ ucfirst($row->kind) }} · {{ $row->ref_count }} {{ \Illuminate\Support\Str::plural($row->level->value, $row->ref_count) }}</span><span class="rg-admin-traffic__note rg-admin-traffic__mono">{{ $row->ref_count === 1 ? $row->ref : implode(', ', array_slice($row->refs ?? [], 0, 6)).($row->ref_count > 6 ? '…' : '') }}</span></span>
                                <span role="cell" class="rg-admin-traffic__note">{{ ucfirst($row->method->value) }} · {{ str_replace('_', ' ', $row->actor->value) }}</span>
                                <span role="cell" class="rg-admin-traffic__perf-label"><x-admin.ui.badge :tone="$row->ok ? 'success' : 'danger'">{{ $row->ok ? 'Done' : 'Failed' }}{{ $row->http_status !== null ? ' · HTTP '.$row->http_status : '' }}</x-admin.ui.badge><span class="rg-admin-traffic__note">{{ $row->note }}</span></span>
                            </div>
                        @endforeach
                        @break
                    @default
                        <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--clicks rg-admin-traffic__perf-row--head" role="row">
                            @foreach (['Time', 'Source', 'Visitor', 'Site · zone', 'Device · place', 'Landing', 'What happened', 'Spend'] as $heading)<span role="columnheader">{{ $heading }}</span>@endforeach
                        </div>
                        @foreach ($logPage->items() as $row)
                            @php([$time, $day] = $when($row->arrived_at))
                            <div class="rg-admin-traffic__log-line rg-admin-traffic__log-line--clicks" role="row" wire:key="click-{{ $row->id }}">
                                <span role="cell" class="rg-admin-traffic__perf-label"><strong>{{ $time }}</strong><span class="rg-admin-traffic__note">{{ $day }}</span></span>
                                <span role="cell">{{ $row->source_id === null ? ucfirst($row->channel->value) : ($labels['source_id'][(string) $row->source_id] ?? '') }}</span>
                                <span role="cell">@if (($visitors[$row->id] ?? null) !== null)@include('filament.pages.traffic-sources.journey-link', ['journey' => $visitors[$row->id]])@else—@endif</span>
                                <span role="cell" class="rg-admin-traffic__mono">{{ implode(' · ', array_filter([$row->site_ref, $row->zone_ref])) ?: '—' }}</span>
                                <span role="cell">{{ ucfirst($row->device->value) }} · {{ $row->country ?? '—' }}{{ $row->city !== null ? ', '.$row->city : '' }}</span>
                                <span role="cell" class="rg-admin-traffic__mono rg-admin-traffic__log-request">{{ $row->detail?->landing_path ?? '—' }}</span>
                                <span role="cell" class="rg-admin-traffic__note">{{ implode(' · ', array_filter([$row->opened_at !== null ? 'Opened' : 'Not opened', $row->started_at !== null ? 'played' : null, $row->account_at !== null ? 'account' : null, $row->known ? 'known' : null, $row->blocked_hit ? 'from a blocked place' : null])) }}</span>
                                <span role="cell" class="rg-admin-traffic__fig">{{ \App\Support\Traffic\Money\TrafficMoney::fine($row->spend) }}</span>
                            </div>
                        @endforeach
                @endswitch
            </div>
        </div>
        <div class="rg-admin-traffic__pager">
            <span class="rg-admin-traffic__note">{{ $log === 'postbacks' ? 'Every postback RateGuru sends, newest first, with the exact request and the network’s answer. Kept for 90 days. An account’s postback goes out when it is confirmed, for the latest paid click within the source’s window, wherever on the site the visitor signed up.' : 'Newest first, fifty to a page.' }}</span>
            <span class="rg-admin-traffic__pager-buttons">
                <x-admin.ui.icon-button icon="chevron-left" label="Newer" size="sm" :disabled="$pageNumber <= 1" wire:click="goToPage({{ $pageNumber - 1 }})" />
                <x-admin.ui.icon-button icon="chevron-right" label="Older" size="sm" :disabled="! $logPage->hasMorePages()" wire:click="goToPage({{ $pageNumber + 1 }})" />
            </span>
        </div>
    @endif
</section>
