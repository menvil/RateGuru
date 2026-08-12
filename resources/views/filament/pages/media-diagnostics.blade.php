<x-filament-panels::page>
    @php
        $totals = $this->totals();
        $health = $this->health();
        $lastAudit = $this->lastAuditRun();
        $healthColor = match ($health) {
            \App\Enums\MediaHealthStatus::Healthy => 'success',
            \App\Enums\MediaHealthStatus::Warning => 'warning',
            \App\Enums\MediaHealthStatus::Critical => 'danger',
            \App\Enums\MediaHealthStatus::Unknown => 'gray',
        };
    @endphp

    <div class="space-y-6">
        <x-filament::section heading="Health overview">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                <div>
                    <p class="text-sm text-gray-500">Status</p>
                    <x-filament::badge :color="$healthColor">{{ ucfirst($health->value) }}</x-filament::badge>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total assets</p>
                    <p class="text-lg font-semibold">{{ number_format($totals['total_assets']) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total variants</p>
                    <p class="text-lg font-semibold">{{ number_format($totals['total_variants']) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Tracked storage size</p>
                    <p class="text-lg font-semibold">{{ number_format($totals['tracked_storage_bytes'] / 1048576, 1) }} MB</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Missing masters</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->missing_masters ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Missing variants</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->missing_variant_files ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Active unreferenced</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->active_unreferenced_assets ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Purgeable</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->purgeable_assets ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Physical orphan candidates</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->physical_orphan_candidates ?? 0) }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Failed media jobs</p>
                    <p class="text-lg font-semibold">{{ number_format($lastAudit?->failed_media_jobs ?? 0) }}</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Last audit">
            @if ($lastAudit === null)
                <p class="text-sm text-gray-500">No audit has ever run. Use "Run full audit" above to record the first snapshot.</p>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <p class="text-sm text-gray-500">Started</p>
                        <p>{{ $lastAudit->started_at->toDayDateTimeString() }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Completed</p>
                        <p>{{ $lastAudit->completed_at?->toDayDateTimeString() ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Duration</p>
                        <p>
                            @if ($lastAudit->completed_at !== null)
                                {{ $lastAudit->started_at->diffForHumans($lastAudit->completed_at, true) }}
                            @else
                                —
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Status</p>
                        <x-filament::badge :color="match ($lastAudit->status) {
                            \App\Enums\MediaAuditRunStatus::Completed => 'success',
                            \App\Enums\MediaAuditRunStatus::Failed => 'danger',
                            \App\Enums\MediaAuditRunStatus::Running => 'warning',
                        }">
                            {{ ucfirst($lastAudit->status->value) }}
                        </x-filament::badge>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Assets checked</p>
                        <p>{{ number_format($lastAudit->assets_checked) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Variants checked</p>
                        <p>{{ number_format($lastAudit->variants_checked) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Issues</p>
                        <p>{{ number_format($lastAudit->issues()->count()) }}</p>
                    </div>
                </div>
            @endif
        </x-filament::section>

        {{ $this->table }}

        <x-filament::section heading="Failed media jobs">
            @php $failedJobs = $this->failedMediaJobs(); @endphp

            @if ($failedJobs === [])
                <p class="text-sm text-gray-500">No failed media jobs.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1 pr-4">Job</th>
                                <th class="py-1 pr-4">Asset ID</th>
                                <th class="py-1 pr-4">Failed at</th>
                                <th class="py-1 pr-4">Exception</th>
                                <th class="py-1">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($failedJobs as $failedJob)
                                <tr class="border-t border-gray-100 dark:border-gray-700">
                                    <td class="py-1 pr-4">{{ class_basename($failedJob->jobClass) }}</td>
                                    <td class="py-1 pr-4">{{ $failedJob->mediaAssetId ?? '—' }}</td>
                                    <td class="py-1 pr-4">{{ $failedJob->failedAt->toDayDateTimeString() }}</td>
                                    <td class="py-1 pr-4 max-w-sm truncate" title="{{ $failedJob->exceptionSummary }}">{{ $failedJob->exceptionSummary }}</td>
                                    <td class="py-1">
                                        @if ($failedJob->mediaAssetId !== null)
                                            <x-filament::button
                                                size="xs"
                                                color="gray"
                                                wire:click="retryFailedMediaJob({{ $failedJob->mediaAssetId }})"
                                            >
                                                Retry
                                            </x-filament::button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Audit history">
            @php $recentRuns = $this->recentRuns(); @endphp

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-1 pr-4">Started</th>
                            <th class="py-1 pr-4">Status</th>
                            <th class="py-1 pr-4">Assets checked</th>
                            <th class="py-1 pr-4">Missing masters</th>
                            <th class="py-1">Purgeable</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentRuns as $run)
                            <tr class="border-t border-gray-100 dark:border-gray-700">
                                <td class="py-1 pr-4">{{ $run->started_at->toDayDateTimeString() }}</td>
                                <td class="py-1 pr-4">{{ ucfirst($run->status->value) }}</td>
                                <td class="py-1 pr-4">{{ number_format($run->assets_checked) }}</td>
                                <td class="py-1 pr-4">{{ number_format($run->missing_masters) }}</td>
                                <td class="py-1">{{ number_format($run->purgeable_assets) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
