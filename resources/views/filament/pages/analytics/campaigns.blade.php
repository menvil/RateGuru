@php
    /*
     * Analytics → Campaigns (CampaignsTab), as the reference draws it: where
     * spend comes from — each way the paid sources count it, with a band on
     * what the networks' APIs report — then the paid sources, each with its
     * visitors and spend, the way to its settings and a click — on its name
     * or its Segments — that filters the page by it; while a paid source is
     * chosen, its segments: All, each segment with its rule and verdict, and
     * the visits of no segment, a segment's drawer (TrafficSegmentEditor)
     * opened from Add segment or its pencil; then Campaign performance, a
     * tree of campaigns, their creatives and the games those were played on,
     * sorted as chosen. A campaign or creative row opens or closes; a game's
     * row leads to the game's analytics on the Games tab. The tables scroll
     * sideways inside their cards where a card is narrower. Nobody matching
     * the filters is said once, with nothing else drawn. Opened from a
     * tracking link, a banner above it all says so, with the way back.
     */
@endphp

@if ($data['link'] !== null)
    <x-admin.ui.inline-notice tone="info" icon="link-2" class="rg-admin-analytics__link-banner" data-testid="analytics-link-banner">
        {{ $data['link']['text'] }}
        <x-slot:actions>
            <x-admin.ui.button size="sm" icon="arrow-left" wire:click="backToLinks" data-testid="analytics-link-back">Back to tracking links</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

@if ($data['empty'])
    @include('filament.pages.analytics.elements.no-visitors', ['range' => $data['range'], 'filtered' => $data['filtered']])
@else
    @php($origin = $data['spendOrigin'])
    <x-admin.ui.card title="Where spend comes from" description="How each paid source counts what it spent. Spend is never guessed from tags, so a campaign named only by utm_campaign costs nothing here." flush clip class="rg-admin-analytics__origin-card" data-testid="analytics-spend-origin">
        <x-slot:aside>
            <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$origin['url']">Open Spend</x-admin.ui.button>
        </x-slot:aside>
        @if ($origin['lines'] !== [])
            <ul class="rg-admin-analytics__origins">
                @foreach ($origin['lines'] as $line)
                    <li class="rg-admin-analytics__origin" data-testid="analytics-spend-origin-line">
                        <span class="rg-admin-analytics__origin-text">
                            <span class="rg-admin-analytics__origin-mode">{{ $line['mode'] }}</span>
                            <span class="rg-admin-analytics__origin-note">{{ $line['note'] }}</span>
                        </span>
                        <span class="rg-admin-analytics__origin-who">{{ $line['who'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
        <div class="rg-admin-analytics__origin-band rg-admin-analytics__origin-band--{{ $origin['band']['tone'] }}" data-testid="analytics-spend-origin-band">
            <x-admin.ui.icon :name="$origin['band']['icon']" :size="16" class="rg-admin-analytics__origin-icon" />
            <span><span class="rg-admin-analytics__origin-title">{{ $origin['band']['title'] }}</span> {{ $origin['band']['text'] }}</span>
        </div>
    </x-admin.ui.card>

    @php($paid = $data['paidSources'])
    <x-admin.ui.card title="Paid sources" :description="$paid['description']" flush clip class="rg-admin-analytics__paid-card" data-testid="analytics-paid-sources">
        @forelse ($paid['rows'] as $row)
            <div
                @class(['rg-admin-analytics__paid', 'rg-admin-analytics__paid--selected' => $row['selected']])
                data-testid="analytics-paid-source"
                wire:key="analytics-paid-{{ $row['key'] }}"
            >
                <span class="rg-admin-analytics__paid-icon" aria-hidden="true"><x-admin.ui.icon :name="$row['icon']" :size="16" /></span>
                <div class="rg-admin-analytics__paid-text">
                    <div class="rg-admin-analytics__paid-head">
                        <button type="button" class="rg-admin-analytics__paid-name" aria-pressed="{{ $row['selected'] ? 'true' : 'false' }}" title="{{ $row['selected'] ? 'Show every source again' : 'Filter the page by '.$row['name'] }}" wire:click="pickSource(@js($row['key']))">{{ $row['name'] }}</button>
                        <span class="rg-admin-analytics__paid-medium">{{ $row['medium'] }}</span>
                        <x-admin.ui.badge :tone="$row['status']['tone']">{{ $row['status']['label'] }}</x-admin.ui.badge>
                    </div>
                    <div class="rg-admin-analytics__paid-note">{{ $row['note'] }}</div>
                </div>
                <span class="rg-admin-analytics__paid-figures">
                    <span class="rg-admin-analytics__paid-people">{{ $row['people'] }}</span>
                    @if ($row['spend'] !== null)
                        <span class="rg-admin-analytics__paid-spend">{{ $row['spend'] }}</span>
                    @endif
                </span>
                <span class="rg-admin-analytics__paid-actions">
                    <x-admin.ui.button size="sm" icon="settings-2" :href="$row['settings']">Settings</x-admin.ui.button>
                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" wire:click="openSegments('{{ $row['key'] }}')" data-testid="analytics-paid-segments">Segments</x-admin.ui.button>
                </span>
            </div>
        @empty
            <x-admin.ui.empty-state icon="megaphone" title="No paid source yet">
                Ad networks and bought placements are set up under Traffic sources → Sources.
                <x-slot:action>
                    <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$paid['setUp']">Open Sources</x-admin.ui.button>
                </x-slot:action>
            </x-admin.ui.empty-state>
        @endforelse
    </x-admin.ui.card>

    @if ($data['segments'] !== null)
        @php($segments = $data['segments'])
        <x-admin.ui.card :title="$segments['title']" :description="$segments['description']" flush clip class="rg-admin-analytics__segments-card" data-testid="analytics-segments">
            <x-slot:aside>
                <span class="rg-admin-analytics__segments-actions">
                    <x-admin.ui.button size="sm" icon="settings-2" :href="$segments['settings']">Source settings</x-admin.ui.button>
                    <x-admin.ui.button
                        size="sm"
                        variant="primary"
                        icon="plus"
                        wire:click="addSegment"
                        :disabled="$segments['full']"
                        :title="$segments['full'] ? 'A source holds up to '.$segments['most'].' segments. Delete one to add another.' : null"
                        data-testid="analytics-segment-add"
                    >Add segment</x-admin.ui.button>
                </span>
            </x-slot:aside>
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid rg-admin-analytics__segments" role="table" aria-label="{{ $segments['title'] }}">
                    <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                        <div class="rg-admin-table__cell" role="columnheader">Segment</div>
                        <div class="rg-admin-table__cell" role="columnheader">Rule</div>
                        @foreach (['Visitors', 'Game starts', 'Registrations', 'Reg. rate', 'Activated', 'Cost / reg.'] as $column)
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                        @endforeach
                        <div class="rg-admin-table__cell" role="columnheader">Verdict</div>
                        <div class="rg-admin-table__cell" role="columnheader"><span class="rg-admin-sr-only">Edit</span></div>
                    </div>
                    @foreach ($segments['rows'] as $row)
                        <div
                            @class(['rg-admin-table__row rg-admin-analytics__segment-row', 'rg-admin-analytics__segment-row--'.$row['kind']])
                            role="row"
                            data-testid="analytics-segment-row"
                            data-kind="{{ $row['kind'] }}"
                            wire:key="analytics-{{ $row['id'] }}"
                        >
                            <div class="rg-admin-table__cell rg-admin-analytics__segment-name-cell" role="cell">
                                <span class="rg-admin-analytics__segment-name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                <span class="rg-admin-analytics__segment-meta">{{ $row['meta'] }}</span>
                            </div>
                            <div class="rg-admin-table__cell rg-admin-analytics__segment-rule" role="cell">
                                @foreach ($row['chips'] as $chip)
                                    <span class="rg-admin-analytics__segment-chip" data-testid="analytics-segment-chip">{{ $chip }}</span>
                                @endforeach
                            </div>
                            @foreach (['visitors', 'starters', 'accounts'] as $cell)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__segment-figure" role="cell">{{ $row['cells'][$cell] }}</div>
                            @endforeach
                            <div
                                @class([
                                    'rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__segment-figure rg-admin-analytics__segment-rate',
                                    'rg-admin-analytics__segment-rate--'.$row['cells']['rate']['tone'] => $row['cells']['rate']['tone'] !== null,
                                ])
                                role="cell"
                                title="{{ $row['cells']['rate']['title'] }}"
                            >{{ $row['cells']['rate']['value'] }}</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__segment-figure" role="cell">{{ $row['cells']['activated'] }}</div>
                            <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__segment-figure', 'rg-admin-analytics__segment-figure--quiet' => $row['cells']['cost'] === '—']) role="cell">{{ $row['cells']['cost'] }}</div>
                            <div class="rg-admin-table__cell" role="cell">
                                @if ($row['verdict'] !== null)
                                    <x-admin.ui.badge :tone="$row['verdict']['tone']" data-testid="analytics-segment-verdict">{{ $row['verdict']['label'] }}</x-admin.ui.badge>
                                @endif
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                                @if ($row['edit'] !== null)
                                    <x-admin.ui.icon-button icon="pencil" label="Edit segment {{ $row['name'] }}" variant="ghost" size="sm" wire:click="editSegment({{ $row['edit'] }})" data-testid="analytics-segment-edit" />
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
            @if ($segments['count'] === 0)
                <p class="rg-admin-analytics__segments-none" data-testid="analytics-segments-none">No segments yet. Add one to see a site, zone, campaign, creative, device or country of {{ $segments['name'] }} on its own.</p>
            @endif
            <x-slot:footer class="rg-admin-analytics__segments-foot" data-testid="analytics-segments-foot">
                @foreach ($segments['foot'] as $line)
                    <span>{{ $line }}</span>
                @endforeach
                <a href="{{ $segments['report'] }}" class="rg-admin-analytics__segments-report">Block a site or zone in Traffic sources → Report<x-admin.ui.icon name="arrow-up-right" :size="12" /></a>
            </x-slot:footer>
        </x-admin.ui.card>

        @if ($segments['editing'] !== null)
            <livewire:admin.traffic-segment-editor
                :source-id="$segments['source']"
                :segment-id="$segments['editing'] === 'new' ? 0 : $segments['editing']"
                :period="(string) $this->period"
                :from="(string) $this->from"
                :to="(string) $this->to"
                :device="(string) $this->device"
                :key="'traffic-segment-'.$segments['source'].'-'.$segments['editing']"
            />
        @endif
    @endif

    <x-admin.ui.card title="Campaign performance" :description="$data['description']" flush class="rg-admin-analytics__campaigns-card" data-testid="analytics-campaigns">
        <x-slot:aside>
            <x-admin.ui.segmented label="Sort" compact :options="$data['sorts']" :value="$data['sort']" wire:model.live="csort" class="rg-admin-analytics__sort" />
        </x-slot:aside>
        @if ($data['rows'] === [])
            <p class="rg-admin-analytics__campaigns-none" data-testid="analytics-campaigns-none">No campaigns match these filters. Visits that came by no paid campaign or utm_campaign appear in Overview by source only.</p>
        @else
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid rg-admin-analytics__campaigns" role="table" aria-label="Campaigns, creatives and games">
                    <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                        <div class="rg-admin-table__cell" role="columnheader">Campaign · creative · game</div>
                        <div class="rg-admin-table__cell" role="columnheader">Source · medium</div>
                        @foreach (['Spend', 'Landing visitors', 'Cost / visitor', 'Game starts', 'Completion', 'Registrations', 'Cost / registration', 'Activated', 'Cost / activated'] as $column)
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                        @endforeach
                    </div>
                    @foreach ($data['rows'] as $row)
                        <div
                            @class([
                                'rg-admin-table__row rg-admin-analytics__campaign-row',
                                'rg-admin-analytics__campaign-row--level-'.$row['level'],
                                'rg-admin-analytics__campaign-row--'.$row['kind'],
                                'rg-admin-analytics__campaign-row--action' => $row['toggle'] !== null || $row['url'] !== null,
                                'rg-admin-analytics__campaign-row--marked' => $row['marked'],
                            ])
                            role="row"
                            data-testid="analytics-campaign-row"
                            data-kind="{{ $row['kind'] }}"
                            data-level="{{ $row['level'] }}"
                            wire:key="analytics-{{ $row['id'] }}"
                            @if ($row['toggle'] !== null)
                                wire:click="toggleCampaignRow(@js($row['toggle']['campaign']), @js($row['toggle']['creative']))"
                            @elseif ($row['url'] !== null)
                                x-on:click="if (! $event.target.closest('a, button')) window.location.href = @js($row['url'])"
                            @endif
                        >
                            <div class="rg-admin-table__cell rg-admin-analytics__campaign-name-cell" role="cell">
                                <span class="rg-admin-analytics__campaign-chevron" aria-hidden="true">
                                    @if ($row['open'] !== null)
                                        <x-admin.ui.icon :name="$row['open'] ? 'chevron-down' : 'chevron-right'" :size="14" />
                                    @elseif ($row['url'] !== null)
                                        <x-admin.ui.icon name="arrow-up-right" :size="14" />
                                    @endif
                                </span>
                                <span class="rg-admin-analytics__campaign-text">
                                    @if ($row['toggle'] !== null)
                                        <button type="button" @class(['rg-admin-analytics__campaign-name', 'rg-admin-analytics__campaign-name--mono' => $row['mono']]) aria-expanded="{{ $row['open'] ? 'true' : 'false' }}" title="{{ $row['name'] }}">{{ $row['name'] }}</button>
                                    @elseif ($row['url'] !== null)
                                        <a href="{{ $row['url'] }}" class="rg-admin-analytics__campaign-name rg-admin-analytics__campaign-name--link" title="The analytics of {{ $row['name'] }}">{{ $row['name'] }}</a>
                                    @else
                                        <span class="rg-admin-analytics__campaign-name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                    @endif
                                    @if ($row['sub'] !== null && $row['sub'] !== '')
                                        <span class="rg-admin-analytics__campaign-sub">{{ $row['sub'] }}</span>
                                    @endif
                                </span>
                                @if ($row['best'])
                                    <x-admin.ui.badge tone="success" class="rg-admin-analytics__campaign-best">Best activation rate</x-admin.ui.badge>
                                @endif
                            </div>
                            <div class="rg-admin-table__cell rg-admin-analytics__campaign-source" role="cell">
                                <span>{{ $row['source']['text'] }}</span>
                                @if ($row['source']['more'] !== null)
                                    <span class="rg-admin-analytics__campaign-source-more">{{ $row['source']['more'] }}</span>
                                @endif
                            </div>
                            @foreach ($row['cells'] as $cell)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__campaign-figure" role="cell" @if ($cell['title'] !== null) title="{{ $cell['title'] }}" @endif>
                                    <span @class([
                                        'rg-admin-analytics__campaign-value',
                                        'rg-admin-analytics__campaign-value--strong' => $cell['strong'],
                                        'rg-admin-analytics__campaign-value--quiet' => $cell['quiet'],
                                        'rg-admin-analytics__campaign-value--small' => $cell['small'],
                                    ])>{{ $cell['value'] }}</span>
                                    @if ($cell['sub'] !== null)
                                        <span @class(['rg-admin-analytics__campaign-rate', 'rg-admin-analytics__campaign-rate--tag' => $cell['tag']])>{{ $cell['sub'] }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        <x-slot:footer data-testid="analytics-campaigns-foot">{{ $data['foot'] }}</x-slot:footer>
    </x-admin.ui.card>
@endif
