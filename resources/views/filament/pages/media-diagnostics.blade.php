@php
    /*
     * Media diagnostics (MediaDiagnosticsPage). The header band with the
     * library's health; the overview's figures, those that count issues
     * narrowing the list to them; the issues of the last completed audit,
     * narrowed, searched and paged on the server; then the last audit, the
     * ones before it and the failed media jobs. The issue's asset opens in
     * the inspector drawer (OVL-02).
     */
    $columns = '--rg-admin-table-columns: 96px 170px 88px 132px 80px minmax(160px, 1fr) 120px 210px; --rg-admin-table-min-width: 1056px';
    $total = $paginator->total();
@endphp

<div class="rg-admin rg-admin-screen" x-data>
    <section class="rg-admin-page-header" aria-labelledby="rg-admin-media-title">
        <div class="rg-admin-page-header__text">
            <div class="rg-admin-user__title-row">
                <h1 id="rg-admin-media-title" class="rg-admin-page-header__title">Media diagnostics</h1>
                <x-admin.ui.badge :tone="$health['tone']" :dot="$health['dot']">{{ $health['label'] }}</x-admin.ui.badge>
            </div>
            <p class="rg-admin-page-header__description rg-admin-rating-group__meta">
                <span>{{ $running ? 'A full audit is running' : $auditLine }}</span>
                @if ($disks !== '')
                    <span class="rg-admin-rating-group__divider" aria-hidden="true"></span>
                    <span>Disks {{ $disks }}</span>
                @endif
            </p>
        </div>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.card flush clip>
            <div class="rg-admin-media__tiles">
                @foreach ($tiles as $tile)
                    @php($filter = $tile['filter'])
                    <{{ $filter !== null ? 'button' : 'div' }}
                        @class(['rg-admin-media__tile', 'rg-admin-media__tile--action' => $filter !== null])
                        @if ($filter !== null)
                            type="button"
                            wire:click="showIssues('{{ $filter['severity'] }}', '{{ $filter['type'] }}')"
                            aria-controls="rg-admin-media-issues"
                        @endif
                    >
                        <span class="rg-admin-overline">{{ $tile['label'] }}</span>
                        <span class="rg-admin-media__tile-value">
                            {{ $tile['value'] }}
                            @if ($tile['badge'] !== null)
                                <x-admin.ui.badge :tone="$tile['badge']['tone']">{{ $tile['badge']['label'] }}</x-admin.ui.badge>
                            @endif
                        </span>
                        <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $tile['note'] }}</span>
                    </{{ $filter !== null ? 'button' : 'div' }}>
                @endforeach
            </div>
        </x-admin.ui.card>

        <section id="rg-admin-media-issues" class="rg-admin-table" aria-labelledby="rg-admin-media-issues-title">
            <div class="rg-admin-toolbar">
                <div class="rg-admin-media__issues-title">
                    <h2 id="rg-admin-media-issues-title" class="rg-admin-card__title">Audit issues</h2>
                    <p class="rg-admin-card__description">{{ $issuesFrom !== null ? 'From the audit that finished '.$issuesFrom : 'No audit has completed yet' }}</p>
                </div>
                <x-admin.ui.search-field class="rg-admin-media__search" placeholder="Asset ID or path" name="q" id="rg-admin-media-search" :value="$q" wire:model.live.debounce.300ms="q" clearable />
                <x-admin.ui.filter-dropdown label="Severity" id="rg-admin-media-severity" :options="$severities" :value="$severity" wire:model.live="severity" />
                <x-admin.ui.filter-dropdown label="Issue type" id="rg-admin-media-type" :options="$types" :value="$type" wire:model.live="type" />
                <span class="rg-admin-toolbar__count" role="status">{{ number_format($total) }} {{ \Illuminate\Support\Str::plural('issue', $total) }}{{ $filtered ? ' match' : '' }}</span>
            </div>

            @if ($rows !== [])
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Audit issues" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Severity</div>
                            <div class="rg-admin-table__cell" role="columnheader">Issue type</div>
                            <div class="rg-admin-table__cell" role="columnheader">Asset ID</div>
                            <div class="rg-admin-table__cell" role="columnheader">Variant</div>
                            <div class="rg-admin-table__cell" role="columnheader">Disk</div>
                            <div class="rg-admin-table__cell" role="columnheader">Path</div>
                            <div class="rg-admin-table__cell" role="columnheader">Detected</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>

                        @foreach ($rows as $row)
                            <div id="rg-admin-media-issue-{{ $row['id'] }}" @class(['rg-admin-table__row', 'rg-admin-media__row', 'rg-admin-media__row--critical' => $row['critical']]) role="row" wire:key="media-issue-{{ $row['id'] }}">
                                <div class="rg-admin-table__cell" role="cell"><x-admin.ui.badge :tone="$row['severity']['tone']">{{ $row['severity']['label'] }}</x-admin.ui.badge></div>
                                <div class="rg-admin-table__cell rg-admin-media__type" role="cell">{{ $row['type'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-media__mono rg-admin-media__mono--strong" role="cell">{{ $row['asset'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-media__mono" role="cell">{{ $row['variant'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-media__mono" role="cell">{{ $row['disk'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-media__mono rg-admin-media__path" role="cell" title="{{ $row['path'] }}">{{ $row['path'] ?? '—' }}</div>
                                <div class="rg-admin-table__cell rg-admin-comments__created" role="cell">{{ $row['detected'] }}</div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                                    @if ($row['regenerate'])
                                        <x-admin.ui.button size="sm" wire:click="regenerate({{ $row['assetId'] }})">Regenerate<span class="rg-admin-sr-only"> asset {{ $row['asset'] }}</span></x-admin.ui.button>
                                    @endif
                                    @if ($row['release'])
                                        <x-admin.ui.button size="sm" wire:click="askToRelease({{ $row['assetId'] }})">Release<span class="rg-admin-sr-only"> asset {{ $row['asset'] }}</span></x-admin.ui.button>
                                    @endif
                                    @if ($row['purge'])
                                        <x-admin.ui.button size="sm" icon="trash-2" wire:click="askToPurge({{ $row['assetId'] }})">Purge<span class="rg-admin-sr-only"> asset {{ $row['asset'] }}</span></x-admin.ui.button>
                                    @endif
                                    @if ($row['assetId'] !== null)
                                        <x-admin.ui.button size="sm" wire:click="inspect({{ $row['id'] }})">Inspect<span class="rg-admin-sr-only"> asset {{ $row['asset'] }}</span></x-admin.ui.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <x-admin.ui.pagination :paginator="$paginator" noun="issues" note="most severe first" action="goToPage" label="Audit issues pages" />
            @elseif ($filtered)
                <x-admin.ui.empty-state icon="search-x" title="No issues match these filters">
                    Try a different search, or remove the filters.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @else
                <x-admin.ui.empty-state icon="circle-check" tone="success" :title="$issuesFrom !== null ? 'No issues' : 'No audit yet'">
                    {{ $issuesFrom !== null ? 'The last audit found nothing to fix.' : 'Run a full audit to record the first snapshot.' }}
                </x-admin.ui.empty-state>
            @endif
        </section>

        <div class="rg-admin-media__cards">
            <div class="rg-admin-user__main">
                <x-admin.ui.card flush>
                    <div class="rg-admin-card__body">
                        <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Last audit</h2></div>
                        @if ($lastAudit === null)
                            <p class="rg-admin-reports__note rg-admin-media__card-note">No audit has ever run. Run full audit records the first snapshot.</p>
                        @else
                            <dl class="rg-admin-detail-list">
                                @foreach ([['play', 'Started', $lastAudit['started']], ['flag', 'Completed', $lastAudit['completed'] ?? '—'], ['timer', 'Duration', $lastAudit['duration'] ?? '—']] as [$icon, $label, $value])
                                    <div class="rg-admin-detail-row">
                                        <x-admin.ui.icon :name="$icon" class="rg-admin-detail-row__icon" />
                                        <dt class="rg-admin-detail-row__label">{{ $label }}</dt>
                                        <dd class="rg-admin-detail-row__value">{{ $value }}</dd>
                                    </div>
                                @endforeach
                                <div class="rg-admin-detail-row">
                                    <x-admin.ui.icon name="activity" class="rg-admin-detail-row__icon" />
                                    <dt class="rg-admin-detail-row__label">Status</dt>
                                    <dd class="rg-admin-detail-row__value"><x-admin.ui.badge :tone="$lastAudit['status']['tone']" :dot="$lastAudit['status']['dot']">{{ $lastAudit['status']['label'] }}</x-admin.ui.badge></dd>
                                </div>
                                @foreach ([['image', 'Assets checked', $lastAudit['assets']], ['layers', 'Variants checked', $lastAudit['variants']], ['triangle-alert', 'Issues', $lastAudit['issues']]] as [$icon, $label, $value])
                                    <div class="rg-admin-detail-row">
                                        <x-admin.ui.icon :name="$icon" class="rg-admin-detail-row__icon" />
                                        <dt class="rg-admin-detail-row__label">{{ $label }}</dt>
                                        <dd class="rg-admin-detail-row__value">{{ $value }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                </x-admin.ui.card>

                <x-admin.ui.card flush>
                    <div class="rg-admin-card__body rg-admin-media__card-head">
                        <div class="rg-admin-section-label">
                            <h2 class="rg-admin-section-label__text">Audit history</h2>
                            <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
                            <span class="rg-admin-section-label__trailing">Last {{ count($history) }} {{ \Illuminate\Support\Str::plural('run', count($history)) }}</span>
                        </div>
                    </div>
                    @forelse ($history as $past)
                        <div class="rg-admin-media__history-row">
                            <div class="rg-admin-table__stack">
                                <span>{{ $past['started'] }}</span>
                                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $past['meta'] }}</span>
                            </div>
                            <x-admin.ui.badge :tone="$past['status']['tone']" :dot="$past['status']['dot']">{{ $past['status']['label'] }}</x-admin.ui.badge>
                        </div>
                    @empty
                        <p class="rg-admin-reports__note rg-admin-media__card-note">No audits yet.</p>
                    @endforelse
                </x-admin.ui.card>
            </div>

            <x-admin.ui.card flush>
                <div class="rg-admin-card__body rg-admin-media__jobs-head">
                    <div class="rg-admin-section-label"><h2 class="rg-admin-section-label__text">Failed media jobs</h2></div>
                    @if (collect($jobs)->whereNotNull('assetId')->isNotEmpty())
                        <x-admin.ui.button size="sm" icon="rotate-ccw" wire:click="retryAllFailedMediaJobs">Retry all</x-admin.ui.button>
                    @endif
                </div>
                @forelse ($jobs as $job)
                    <div class="rg-admin-media__job">
                        <span class="rg-admin-media__job-icon" aria-hidden="true"><x-admin.ui.icon name="circle-x" :size="16" /></span>
                        <div class="rg-admin-media__job-body">
                            <div class="rg-admin-media__job-head">
                                <span class="rg-admin-media__mono rg-admin-media__mono--strong">{{ $job['job'] }}</span>
                                @if ($job['assetId'] !== null)
                                    <span class="rg-admin-media__mono">#{{ $job['assetId'] }}</span>
                                @endif
                                <span class="rg-admin-media__job-time">{{ $job['failedAt'] }}</span>
                            </div>
                            <p class="rg-admin-media__exception">{{ $job['exception'] }}</p>
                        </div>
                        @if ($job['assetId'] !== null)
                            <x-admin.ui.button size="sm" wire:click="retryFailedMediaJob({{ $job['assetId'] }})">Retry<span class="rg-admin-sr-only"> asset #{{ $job['assetId'] }}</span></x-admin.ui.button>
                        @endif
                    </div>
                @empty
                    <p class="rg-admin-reports__note rg-admin-media__card-note">No failed jobs. The media queue is healthy.</p>
                @endforelse
            </x-admin.ui.card>
        </div>
    </div>

    @if ($inspector !== null)
        @include('filament.pages.media-diagnostics.asset-inspector')
    @endif

    @if ($confirmation !== null)
        @include('filament.pages.media-diagnostics.confirmation')
    @endif
</div>
