@php
    /*
     * A game's Versions tab (GameVersions): a new version — one content set
     * played through one flow — with the publish report line by line and
     * Publish, asked first; then every version published, newest first.
     */
    $columns = '--rg-admin-table-columns: 110px minmax(180px, 1.2fr) minmax(180px, 1.2fr) minmax(160px, 1fr) 150px 90px 160px; --rg-admin-table-min-width: 1040px';
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        <x-admin.ui.card title="New version" description="A version is one content set played through one flow. Publishing freezes both — and the crowd’s votes as they stand — so nothing edited later changes it.">
            <div class="rg-admin-games__publish">
                <fieldset class="rg-admin-games__fieldset">
                    <legend class="rg-admin-label">Content set</legend>
                    @forelse ($sets as $set)
                        <label class="rg-admin-games__choice">
                            <input type="radio" value="{{ $set['id'] }}" wire:model.live="publishSetId" />
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-games__choice-title">{{ $set['name'] }} <x-admin.ui.badge :tone="$set['state']['tone']">{{ $set['state']['label'] }}</x-admin.ui.badge></span>
                                <span class="rg-admin-games__note">{{ $set['rounds'] }} {{ \Illuminate\Support\Str::plural('round', $set['rounds']) }}</span>
                            </span>
                        </label>
                    @empty
                        <p class="rg-admin-games__note">No content set that isn’t archived.</p>
                    @endforelse
                </fieldset>
                <fieldset class="rg-admin-games__fieldset">
                    <legend class="rg-admin-label">Flow</legend>
                    @forelse ($flows as $flow)
                        <label class="rg-admin-games__choice">
                            <input type="radio" value="{{ $flow['id'] }}" wire:model.live="publishFlowId" />
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-games__choice-title">{{ $flow['name'] }} <x-admin.ui.badge :tone="$flow['valid'] ? 'success' : 'warning'">{{ $flow['valid'] ? 'Valid' : 'To fix' }}</x-admin.ui.badge></span>
                                <span class="rg-admin-games__note">{{ $flow['chain'] }}</span>
                            </span>
                        </label>
                    @empty
                        <p class="rg-admin-games__note">No flow that isn’t archived.</p>
                    @endforelse
                </fieldset>

                <div class="rg-admin-games__checks">
                    <span class="rg-admin-label">Before publishing</span>
                    @if ($readiness !== null)
                        <ul class="rg-admin-games__check-list">
                            @foreach ($readiness->lines as $line)
                                <li @class(['rg-admin-games__check', 'rg-admin-games__check--ok' => $line->ok])>
                                    <x-admin.ui.icon :name="$line->ok ? 'circle-check' : 'circle-x'" :size="16" />
                                    <span>{{ $line->text }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($changedSince !== null)
                        <p class="rg-admin-games__note">This set or flow, or a translation of it, changed after {{ $changedSince }} was published from them.</p>
                    @elseif ($duplicateOf !== null)
                        <p class="rg-admin-games__note">{{ $duplicateOf }} already uses this set and flow, and neither changed since. Publish again to freeze today’s votes, or to take in what changed on the General tab and the translations of the game’s title and description.</p>
                    @endif
                    @if ($takeOvers !== [])
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Traffic</span>
                            <select class="rg-admin-games__select" wire:model="takeOver">
                                <option value="">No traffic until a share is set in Traffic</option>
                                @foreach ($takeOvers as $version)
                                    <option value="{{ $version['id'] }}">Take over {{ $version['label'] }}’s shares</option>
                                @endforeach
                            </select>
                        </label>
                    @else
                        <p class="rg-admin-games__note">A new version gets no traffic until a share is set in Traffic.</p>
                    @endif
                    <div class="rg-admin-games__set-tools">
                        <x-admin.ui.button variant="primary" :icon="$readiness?->isReady() ? 'check' : 'lock'" :disabled="! ($readiness?->isReady() ?? false)" wire:click="askPublish">{{ $readiness?->isReady() ? 'Publish '.$nextLabel : 'Can’t publish yet' }}</x-admin.ui.button>
                    </div>
                </div>
            </div>
        </x-admin.ui.card>

        <div class="rg-admin-table">
            <div class="rg-admin-toolbar">
                <span class="rg-admin-label">Published versions</span>
                @if ($retiredCount > 0)
                    <x-admin.ui.switch label="Show archived" id="rg-admin-versions-retired" :checked="$showRetired" wire:model.live="showRetired" />
                @endif
                <x-admin.ui.button size="sm" :href="$trafficUrl" trailing-icon="arrow-right">Set traffic</x-admin.ui.button>
            </div>
            @if ($versions === [] && $retiredCount > 0)
                <x-admin.ui.empty-state icon="archive" title="No active versions">{{ $retiredCount }} archived. Turn on Show archived to see {{ $retiredCount === 1 ? 'it' : 'them' }}.</x-admin.ui.empty-state>
            @elseif ($versions === [])
                <x-admin.ui.empty-state icon="layers" title="No versions yet">Publish a content set played through a flow to make the first version.</x-admin.ui.empty-state>
            @else
                <div class="rg-admin-table__scroll">
                    <div class="rg-admin-table__grid" role="table" aria-label="Versions" style="{{ $columns }}">
                        <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                            <div class="rg-admin-table__cell" role="columnheader">Version</div>
                            <div class="rg-admin-table__cell" role="columnheader">Content set</div>
                            <div class="rg-admin-table__cell" role="columnheader">Flow</div>
                            <div class="rg-admin-table__cell" role="columnheader">Traffic</div>
                            <div class="rg-admin-table__cell" role="columnheader">Published</div>
                            <div class="rg-admin-table__cell" role="columnheader">Attempts</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--end" role="columnheader">Actions</div>
                        </div>
                        @foreach ($versions as $version)
                            <div class="rg-admin-table__row" role="row" wire:key="version-{{ $version['id'] }}">
                                <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                    <div class="rg-admin-table__stack">
                                        <span class="rg-admin-table__name">{{ $version['label'] }}</span>
                                        <x-admin.ui.badge :tone="$version['state']['tone']" :dot="$version['state']['dot']">{{ $version['state']['label'] }}</x-admin.ui.badge>
                                    </div>
                                </div>
                                <div class="rg-admin-table__cell" role="cell"><div class="rg-admin-table__stack"><span class="rg-admin-table__ellipsis">{{ $version['set'] }}</span><span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $version['rounds'] }} rounds as published</span></div></div>
                                <div class="rg-admin-table__cell" role="cell"><span class="rg-admin-table__ellipsis">{{ $version['flow'] }}</span></div>
                                <div class="rg-admin-table__cell" role="cell">
                                    <div class="rg-admin-table__stack">
                                        <span @class(['rg-admin-table__none' => $version['traffic'] === ''])>{{ $version['traffic'] !== '' ? $version['traffic'] : 'No traffic' }}</span>
                                        @if ($version['unplayable'])
                                            <span class="rg-admin-games__status rg-admin-games__status--issue">A post it shows left the site: it can’t be played</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="rg-admin-table__cell" role="cell"><div class="rg-admin-table__stack"><span class="rg-admin-table__meta">{{ $version['published'] }}</span>@if ($version['by'])<span class="rg-admin-table__meta rg-admin-table__meta--small">{{ $version['by'] }}</span>@endif</div></div>
                                <div class="rg-admin-table__cell" role="cell"><span class="rg-admin-table__meta">{{ number_format($version['attempts']) }}</span></div>
                                <div class="rg-admin-table__cell rg-admin-table__cell--end" role="cell">
                                    <x-admin.ui.button size="sm" variant="ghost" icon="eye" :href="$version['previewUrl']">Preview</x-admin.ui.button>
                                    @if ($version['active'])
                                        <x-admin.ui.button size="sm" variant="ghost" icon="archive" :disabled="$version['inTraffic']" :title="$version['inTraffic'] ? 'Remove it from Traffic first' : null" wire:click="retire({{ $version['id'] }})">Archive</x-admin.ui.button>
                                    @else
                                        <x-admin.ui.button size="sm" variant="ghost" icon="archive-restore" wire:click="restore({{ $version['id'] }})">Restore</x-admin.ui.button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    @if ($confirmingPublish)
        <x-admin.ui.confirm-dialog id="rg-admin-game-publish" icon="layers" :title="'Publish '.$nextLabel.'?'" x-on:dismiss="$wire.closePublish()">
            <p>The content set and flow are frozen into {{ $nextLabel }}, with the crowd’s votes as they stand now. {{ $takeOver !== '' ? 'It takes over the chosen version’s traffic at once.' : 'Visitors get it only once it has a share in Traffic.' }}</p>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="publish" wire:loading.attr="disabled">Publish {{ $nextLabel }}</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
