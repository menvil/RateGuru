{{--
    The asset an issue points to, in the drawer (OVL-02): what the issue
    means for it, its details and its files — the master and each variant,
    there or missing, checked on disk now, for this one asset — and what can
    be done: replace all its variants, under its files, and in the footer
    regenerate the missing ones, release it, purge it, or nothing automatic
    for a missing master.
--}}
<x-admin.ui.drawer
    id="rg-admin-media-inspector"
    :title="'Asset #'.$inspector['assetId']"
    :subtitle="$inspector['severity']['label'].' · '.$inspector['typeLabel'].($inspector['detected'] !== null ? ' · detected '.$inspector['detected'] : '')"
    wire:key="media-inspector-{{ $inspector['issueId'] }}"
    x-on:dismiss="$wire.closeInspector()"
>
    @if (! $inspector['exists'])
        <div class="rg-admin-media__section">
            <x-admin.ui.inline-notice tone="info">Asset #{{ $inspector['assetId'] }} no longer exists.</x-admin.ui.inline-notice>
        </div>
    @else
        <div class="rg-admin-media__section">
            <x-admin.ui.inline-notice :tone="$inspector['severity']['tone'] === 'info' ? 'info' : 'warning'">{{ $inspector['explain'] }}</x-admin.ui.inline-notice>
        </div>

        <div class="rg-admin-media__section">
            <div class="rg-admin-section-label"><h3 class="rg-admin-section-label__text">Asset</h3></div>
            <dl class="rg-admin-detail-list">
                @foreach ($inspector['details'] as $detail)
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon :name="$detail['icon']" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">{{ $detail['label'] }}</dt>
                        <dd @class(['rg-admin-detail-row__value', 'rg-admin-detail-row__value--mono' => $detail['mono'], 'rg-admin-media__detail']) title="{{ $detail['value'] }}">{{ $detail['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div class="rg-admin-media__section">
            <div class="rg-admin-section-label">
                <h3 class="rg-admin-section-label__text">Files</h3>
                <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
                <span class="rg-admin-section-label__trailing">{{ $inspector['filesSummary'] }}</span>
            </div>
            <ul class="rg-admin-media__files">
                @foreach ($inspector['files'] as $file)
                    <li @class(['rg-admin-media__file', 'rg-admin-media__file--missing' => ! $file['exists']])>
                        <x-admin.ui.icon :name="$file['exists'] ? 'circle-check' : 'circle-x'" :size="16" :label="$file['exists'] ? 'On disk' : 'Missing'" />
                        <span class="rg-admin-media__mono">{{ $file['name'] }}</span>
                        <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $file['meta'] }}</span>
                    </li>
                @endforeach
            </ul>
            @if ($inspector['canRegenerate'])
                <div class="rg-admin-media__files-action">
                    <x-admin.ui.button size="sm" variant="ghost" icon="refresh-cw" wire:click="askToForceRegenerate({{ $inspector['assetId'] }})">Force regenerate all variants</x-admin.ui.button>
                </div>
            @endif
        </div>
    @endif

    <x-slot:footer>
        @if ($inspector['path'] !== null)
            <x-admin.ui.button icon="copy" :data-path="$inspector['path']" x-data="{ copied: false }" x-on:click="navigator.clipboard?.writeText($el.dataset.path); copied = true; setTimeout(() => (copied = false), 1500)">
                <span x-text="copied ? 'Copied' : 'Copy path'">Copy path</span>
            </x-admin.ui.button>
        @endif
        <span class="rg-admin-reports__spacer"></span>
        @if ($inspector['canRelease'])
            <x-admin.ui.button variant="primary" wire:click="askToRelease({{ $inspector['assetId'] }})">Release asset</x-admin.ui.button>
        @elseif ($inspector['canPurge'])
            <x-admin.ui.button variant="danger" icon="trash-2" wire:click="askToPurge({{ $inspector['assetId'] }})">Purge asset</x-admin.ui.button>
        @elseif ($inspector['canRegenerate'])
            <x-admin.ui.button variant="primary" icon="refresh-cw" wire:click="regenerateVariants({{ $inspector['assetId'] }})">Regenerate missing variants</x-admin.ui.button>
        @elseif ($inspector['exists'])
            <span class="rg-admin-hint">No automatic fix: a new upload or a backup restore is needed.</span>
        @endif
    </x-slot:footer>
</x-admin.ui.drawer>
