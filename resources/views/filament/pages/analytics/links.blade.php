@php
    /*
     * Analytics → Tracking links (TrackingLinksTab), as the reference draws
     * it: the heading with Create tracking link, then a card with the search
     * and how many links there are, and the table of links — each with where
     * it leads, its source and medium, its campaign and creative, its
     * visitors, registrations and activated users of the period, and when and
     * by whom it was made — whose row opens the link's details and whose
     * buttons copy the address, open the link's campaign, edit and duplicate
     * it. The table scrolls sideways inside the card where it is narrower.
     * The details drawer shows the address, the figures or that no visit came
     * yet, the tags and the same actions; the editor's drawer is its own
     * component (TrackingLinkEditor).
     */
    // Says Link copied only once the clipboard took the address: the write can be refused, and there is no clipboard outside a secure page.
    $copy = "async () => { try { await navigator.clipboard.writeText(\$el.dataset.copy); \$dispatch('rg-admin-toast', { message: 'Link copied', tone: 'success' }) } catch { \$dispatch('rg-admin-toast', { message: 'The browser refused the clipboard, so nothing was copied.', tone: 'error' }) } }";
@endphp

<div class="rg-admin-analytics__links-head">
    <div class="rg-admin-analytics__links-heading">
        <h2 class="rg-admin-analytics__links-title">Tracking links</h2>
        <p class="rg-admin-analytics__links-description">{{ $data['description'] }}</p>
    </div>
    <x-admin.ui.button variant="primary" icon="plus" wire:click="createLink" data-testid="analytics-link-create">Create tracking link</x-admin.ui.button>
</div>

<x-admin.ui.card flush clip class="rg-admin-analytics__links-card" data-testid="analytics-links">
    <div class="rg-admin-analytics__links-toolbar">
        <x-admin.ui.search-field
            placeholder="Search name, campaign or creative"
            id="rg-admin-analytics-links-search"
            class="rg-admin-analytics__links-search"
            :value="(string) $this->q"
            clearable
            wire:model.live.debounce.300ms="q"
        />
        <span class="rg-admin-analytics__links-count" data-testid="analytics-links-count">{{ $data['count'] }}</span>
    </div>

    @if ($data['rows'] === [])
        <p class="rg-admin-analytics__links-none" data-testid="analytics-links-none">{{ $data['none'] }}</p>
    @else
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-analytics__links" role="table" aria-label="Tracking links">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell" role="columnheader">Link · destination</div>
                    <div class="rg-admin-table__cell" role="columnheader">Source · medium</div>
                    <div class="rg-admin-table__cell" role="columnheader">Campaign · creative</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Visitors</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Registrations</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Activated</div>
                    <div class="rg-admin-table__cell" role="columnheader">Created</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                </div>
                @foreach ($data['rows'] as $row)
                    <div
                        @class(['rg-admin-table__row rg-admin-analytics__link-row', 'rg-admin-analytics__link-row--selected' => $row['selected']])
                        role="row"
                        data-testid="analytics-link-row"
                        wire:key="analytics-link-{{ $row['id'] }}"
                        x-on:click="if (! $event.target.closest('a, button')) $wire.showLink({{ $row['id'] }})"
                    >
                        <div class="rg-admin-table__cell rg-admin-analytics__link-cell" role="cell">
                            <span class="rg-admin-analytics__link-line">
                                <button type="button" class="rg-admin-analytics__link-name" title="{{ $row['name'] }}" wire:click="showLink({{ $row['id'] }})">{{ $row['name'] }}</button>
                                @if ($row['new'])
                                    <x-admin.ui.badge tone="outline" class="rg-admin-analytics__link-new">New</x-admin.ui.badge>
                                @endif
                            </span>
                            <span class="rg-admin-analytics__link-sub rg-admin-analytics__link-sub--mono" title="{{ $row['destination'] }}">{{ $row['destination'] }}</span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-analytics__link-cell" role="cell">
                            <span class="rg-admin-analytics__link-text">{{ $row['source'] }}</span>
                            <span class="rg-admin-analytics__link-sub rg-admin-analytics__link-sub--mono">{{ $row['medium'] }}</span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-analytics__link-cell" role="cell">
                            <span class="rg-admin-analytics__link-text rg-admin-analytics__link-text--mono" title="{{ $row['campaign'] }}">{{ $row['campaign'] }}</span>
                            <span class="rg-admin-analytics__link-sub rg-admin-analytics__link-sub--mono" title="{{ $row['creative'] }}">{{ $row['creative'] }}</span>
                        </div>
                        <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__link-figure rg-admin-analytics__link-figure--strong', 'rg-admin-analytics__link-figure--quiet' => ! $row['visited']]) role="cell">{{ $row['visitors'] }}</div>
                        @foreach (['accounts', 'activated'] as $cell)
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__link-cell rg-admin-analytics__link-cell--numeric" role="cell">
                                <span class="rg-admin-analytics__link-figure">{{ $row[$cell]['value'] }}</span>
                                @if ($row[$cell]['rate'] !== null)
                                    <span class="rg-admin-analytics__link-rate">{{ $row[$cell]['rate'] }}</span>
                                @endif
                            </div>
                        @endforeach
                        <div class="rg-admin-table__cell rg-admin-analytics__link-cell" role="cell">
                            <span class="rg-admin-analytics__link-date">{{ $row['created'] }}</span>
                            <span class="rg-admin-analytics__link-sub" title="{{ $row['by'] }}">{{ $row['by'] }}</span>
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--end rg-admin-analytics__link-actions" role="cell">
                            <x-admin.ui.icon-button icon="copy" label="Copy URL" variant="ghost" size="sm" :data-copy="$row['url']" x-on:click="{!! $copy !!}" data-testid="analytics-link-copy-url" />
                            <x-admin.ui.icon-button icon="chart-no-axes-column" label="View analytics" variant="ghost" size="sm" :href="$row['analytics']" data-testid="analytics-link-analytics" />
                            <x-admin.ui.icon-button icon="pencil" :label="$row['editLabel'].' '.$row['reference']" variant="ghost" size="sm" wire:click="editLink({{ $row['id'] }})" data-testid="analytics-link-edit" />
                            <x-admin.ui.icon-button icon="copy-plus" :label="'Duplicate '.$row['reference']" variant="ghost" size="sm" wire:click="duplicateLink({{ $row['id'] }})" data-testid="analytics-link-duplicate-row" />
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <x-slot:footer class="rg-admin-analytics__links-foot" data-testid="analytics-links-foot">
        <span>{{ $data['foot'] }}</span>
        @if ($data['more'] !== null)
            <span>{{ $data['more'] }}</span>
        @endif
    </x-slot:footer>
</x-admin.ui.card>

@if ($data['detail'] !== null)
    @php($detail = $data['detail'])
    <x-admin.ui.drawer
        id="rg-admin-tracking-link-detail"
        wide
        :title="$detail['name']"
        :subtitle="$detail['sub']"
        x-on:dismiss="$wire.closeLinkDetails()"
        data-testid="analytics-link-detail"
        wire:key="analytics-link-detail-{{ $detail['id'] }}"
    >
        <div class="rg-admin-analytics__link-detail">
            <div class="rg-admin-analytics__link-section">
                <div class="rg-admin-analytics__link-url" data-testid="analytics-link-detail-url">{{ $detail['url'] }}</div>
                <div class="rg-admin-analytics__link-buttons">
                    <x-admin.ui.button size="sm" icon="copy" :data-copy="$detail['url']" x-on:click="{!! $copy !!}" data-testid="analytics-link-detail-copy">Copy link</x-admin.ui.button>
                    <x-admin.ui.button size="sm" icon="external-link" :href="$detail['preview']" target="_blank">Open destination preview</x-admin.ui.button>
                </div>
            </div>

            @if ($detail['empty'])
                <div class="rg-admin-analytics__link-empty" data-testid="analytics-link-detail-empty">
                    <x-admin.ui.empty-state icon="link-2" title="No visits yet">
                        Visitors, game starts and registrations appear here after people open this link. Creating it didn’t publish a post or start an ad.
                    </x-admin.ui.empty-state>
                </div>
            @else
                <div class="rg-admin-analytics__link-section">
                    <div class="rg-admin-analytics__link-metrics" data-testid="analytics-link-detail-metrics">
                        @foreach ($detail['metrics'] as $metric)
                            <div class="rg-admin-analytics__link-metric">
                                <span class="rg-admin-analytics__link-metric-label">{{ $metric['label'] }}</span>
                                <span class="rg-admin-analytics__link-metric-value">{{ $metric['value'] }}</span>
                                <span class="rg-admin-analytics__link-metric-note">{{ $metric['note'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <span class="rg-admin-analytics__link-period">{{ $detail['period'] }}</span>
                </div>
            @endif

            <dl class="rg-admin-detail-list rg-admin-analytics__link-meta">
                @foreach ($detail['meta'] as $meta)
                    <div class="rg-admin-detail-row">
                        <x-admin.ui.icon :name="$meta['icon']" :size="16" class="rg-admin-detail-row__icon" />
                        <dt class="rg-admin-detail-row__label">{{ $meta['label'] }}</dt>
                        <dd class="rg-admin-detail-row__value rg-admin-analytics__link-meta-value">{{ $meta['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <x-slot:footer>
            <x-admin.ui.button size="sm" icon="pencil" wire:click="editLink({{ $detail['id'] }})" data-testid="analytics-link-detail-edit">{{ $detail['editLabel'] }}</x-admin.ui.button>
            <x-admin.ui.button size="sm" icon="copy-plus" wire:click="duplicateLink({{ $detail['id'] }})">Duplicate</x-admin.ui.button>
            @if ($detail['game'] !== null)
                <x-admin.ui.button size="sm" icon="gamepad-2" :href="$detail['game']">Open game</x-admin.ui.button>
            @endif
            <x-admin.ui.button size="sm" variant="primary" trailing-icon="arrow-right" :href="$detail['analytics']" class="rg-admin-analytics__link-detail-primary" data-testid="analytics-link-detail-campaigns">View in Campaigns</x-admin.ui.button>
        </x-slot:footer>
    </x-admin.ui.drawer>
@endif

@if ($data['editing'] !== null)
    <livewire:admin.tracking-link-editor
        :link-id="$data['editing']['linkId']"
        :copy-of="$data['editing']['copyOf']"
        :key="'tracking-link-'.$data['editing']['key']"
    />
@endif
