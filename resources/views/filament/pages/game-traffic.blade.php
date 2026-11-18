@php
    /*
     * A game's Traffic tab (GameTraffic): each audience's shares between the
     * active versions, applied on its own; the splits for a source — paid
     * clicks of one source or campaign given shares of their own, each with
     * its visitors, Edit, End and its results — and the form that sets one,
     * with the word that splits send nobody while Record visits is off and
     * the way to Traffic sources when there is no source to choose;
     * and the periods allocations were in effect, newest first, each with
     * its scope, the visitors it assigned and the way to its results in
     * Growth → Experiments.
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        @if ($noVersions)
            <x-admin.ui.inline-notice tone="info">
                Publish a version before giving it traffic.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" :href="$versionsUrl">Go to Versions</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @elseif ($paused)
            <x-admin.ui.inline-notice tone="warning">The game is paused: nobody can start it. Shares and periods are kept.</x-admin.ui.inline-notice>
        @elseif ($archived)
            <x-admin.ui.inline-notice tone="info">The game is archived: nobody can play it.</x-admin.ui.inline-notice>
        @endif

        <div class="rg-admin-games__traffic">
            @foreach ($groups as $group)
                <x-admin.ui.card :title="$group['label']" :description="$group['note']" wire:key="traffic-{{ $group['audience'] }}">
                    <div class="rg-admin-games__form">
                        @forelse ($group['rows'] as $row)
                            <div class="rg-admin-games__share" wire:key="share-{{ $group['audience'] }}-{{ $row['id'] }}">
                                <span class="rg-admin-games__mechanic-text">
                                    <span class="rg-admin-games__mechanic-name">{{ $row['label'] }}</span>
                                    <span class="rg-admin-games__note">{{ $row['made'] }} · published {{ $row['published'] }}</span>
                                    @if ($row['unplayable'])
                                        <span class="rg-admin-games__status rg-admin-games__status--issue">Can’t be played: its share goes to the others</span>
                                    @endif
                                </span>
                                <x-admin.ui.number-stepper :label="$row['label'].' share'" :id="'rg-admin-share-'.$group['audience'].'-'.$row['id']" :value="$shares[$group['audience']][$row['id']] ?? 0" :min="0" :max="100" unit="%" wire:model.live.debounce.300ms="shares.{{ $group['audience'] }}.{{ $row['id'] }}" />
                                <x-admin.ui.icon-button icon="x" :label="'Remove '.$row['label']" variant="ghost" size="sm" wire:click="removeVersion('{{ $group['audience'] }}', {{ $row['id'] }})" />
                            </div>
                        @empty
                            <p class="rg-admin-games__note">No traffic: nobody of this audience can be given a version.</p>
                        @endforelse

                        @if ($group['addable'] !== [])
                            <div class="rg-admin-games__set-tools">
                                @foreach ($group['addable'] as $version)
                                    <x-admin.ui.button size="sm" icon="plus" wire:click="addVersion('{{ $group['audience'] }}', {{ $version['id'] }})">Add {{ $version['label'] }}</x-admin.ui.button>
                                @endforeach
                            </div>
                        @endif

                        <p @class(['rg-admin-games__note', 'rg-admin-games__status--issue' => $group['issues'] !== []])>
                            {{ $group['issues'] !== [] ? $group['issues'][0] : ($group['rows'] === [] ? 'Applying no shares turns this audience’s traffic off.' : 'Shares add up to 100%.') }}
                        </p>

                        <div class="rg-admin-games__set-tools">
                            <x-admin.ui.button size="sm" :disabled="$group['rows'] === []" wire:click="splitEvenly('{{ $group['audience'] }}')">Split evenly</x-admin.ui.button>
                            <x-admin.ui.button size="sm" :disabled="! $group['changed']" wire:click="resetShares('{{ $group['audience'] }}')">Reset</x-admin.ui.button>
                            <x-admin.ui.button size="sm" variant="primary" :disabled="! $group['changed'] || $group['issues'] !== []" wire:click="apply('{{ $group['audience'] }}')">Apply shares</x-admin.ui.button>
                        </div>
                    </div>
                </x-admin.ui.card>
            @endforeach
        </div>

        <x-admin.ui.card class="rg-admin-games__splits" title="Splits for a source" description="Anonymous visitors from one paid source, or one of its campaigns, get shares of their own. The closest split wins: campaign, then source, then Anonymous visitors above. Logged-in players and unpaid visitors always get the split above." wire:key="traffic-splits" data-testid="traffic-splits" flush>
            <x-slot:aside>
                @if ($splitForm === null && ! $noVersions)
                    <x-admin.ui.button size="sm" icon="plus" wire:click="openSplit">Split for a source</x-admin.ui.button>
                @endif
            </x-slot:aside>

            @if (! $recording)
                <x-admin.ui.inline-notice tone="warning" strip data-testid="traffic-splits-recording">
                    Visits aren’t recorded, so no click knows its source and splits send nobody. Switch on Record visits under Traffic sources → Sources.
                    @if ($trafficUrl !== null)
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$trafficUrl">Record visits</x-admin.ui.button>
                        </x-slot:actions>
                    @endif
                </x-admin.ui.inline-notice>
            @endif

            {{-- The list and the form each in a section of their own, a rule between them. --}}
            @if ($splits === [] && $splitForm === null)
                <div class="rg-admin-card__body">
                    <p class="rg-admin-games__note">No splits for a source yet: anonymous visitors from every source share the split above.</p>
                </div>
            @endif

            @if ($splits !== [])
                <div class="rg-admin-card__body">
                    <ul class="rg-admin-games__profiles">
                        @foreach ($splits as $item)
                            <li class="rg-admin-games__profile rg-admin-games__split" wire:key="split-{{ $item['id'] }}" data-testid="traffic-split">
                                <span class="rg-admin-games__mechanic-text">
                                    <span class="rg-admin-games__mechanic-name">{{ $item['scope'] }} · {{ $item['shares'] }}</span>
                                    <span class="rg-admin-games__note">Since {{ $item['since'] }} · {{ number_format($item['visitors']) }} {{ \Illuminate\Support\Str::plural('visitor', $item['visitors']) }} assigned</span>
                                    @if ($item['idle'])
                                        <span class="rg-admin-games__status rg-admin-games__status--issue">None of its versions can be played: its clicks get the next split that covers them</span>
                                    @endif
                                </span>
                                <span class="rg-admin-games__split-actions">
                                    <x-admin.ui.button size="sm" icon="pencil" wire:click="openSplit({{ $item['id'] }})">Edit</x-admin.ui.button>
                                    <x-admin.ui.button size="sm" icon="circle-stop" wire:click="askEndSplit({{ $item['id'] }})">End</x-admin.ui.button>
                                    @if ($item['resultsUrl'] !== null)
                                        <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$item['resultsUrl']">Results</x-admin.ui.button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($splitForm !== null && $splitForm['sources'] === [])
                {{-- Nothing to choose from: the way to Traffic sources rather than an empty list. --}}
                <div class="rg-admin-card__body">
                    <div class="rg-admin-games__form" data-testid="traffic-split-form">
                        <p class="rg-admin-games__note" data-testid="traffic-split-note">No ad network or banner to split for yet: add one under Traffic sources.</p>
                        <div class="rg-admin-games__set-tools">
                            @if ($trafficUrl !== null)
                                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$trafficUrl">Open Traffic sources</x-admin.ui.button>
                            @endif
                            <x-admin.ui.button size="sm" wire:click="closeSplit">Cancel</x-admin.ui.button>
                        </div>
                    </div>
                </div>
            @elseif ($splitForm !== null)
                <div class="rg-admin-card__body">
                    <div class="rg-admin-games__form rg-admin-games__form--grid rg-admin-games__split-form" data-testid="traffic-split-form">
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Source</span>
                            <select class="rg-admin-games__select" wire:model.live="split.source" data-testid="traffic-split-source">
                                <option value="">Choose a source…</option>
                                @foreach ($splitForm['sources'] as $source)
                                    <option value="{{ $source['id'] }}">{{ $source['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="rg-admin-field">
                            <span class="rg-admin-label">Campaign</span>
                            <select class="rg-admin-games__select" wire:model.live="split.campaign" @disabled($splitForm['campaigns'] === []) data-testid="traffic-split-campaign">
                                <option value="">Every campaign of the source</option>
                                @foreach ($splitForm['campaigns'] as $campaign)
                                    <option value="{{ $campaign['id'] }}">{{ $campaign['label'] }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($splitForm['rows'] !== [])
                            <div class="rg-admin-games__split-shares">
                                @foreach ($splitForm['rows'] as $row)
                                    <div class="rg-admin-games__share" wire:key="split-share-{{ $row['id'] }}">
                                        <span class="rg-admin-games__mechanic-text">
                                            <span class="rg-admin-games__mechanic-name">{{ $row['label'] }}</span>
                                            <span class="rg-admin-games__note">{{ $row['made'] }} · published {{ $row['published'] }}</span>
                                            @if ($row['unplayable'])
                                                <span class="rg-admin-games__status rg-admin-games__status--issue">Can’t be played: its share goes to the others</span>
                                            @endif
                                        </span>
                                        <x-admin.ui.number-stepper :label="$row['label'].' share of the split'" :id="'rg-admin-split-share-'.$row['id']" :value="$split['shares'][$row['id']] ?? 0" :min="0" :max="100" unit="%" wire:model.live.debounce.300ms="split.shares.{{ $row['id'] }}" />
                                        <x-admin.ui.icon-button icon="x" :label="'Remove '.$row['label'].' from the split'" variant="ghost" size="sm" wire:click="removeSplitVersion({{ $row['id'] }})" />
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($splitForm['addable'] !== [])
                            <div class="rg-admin-games__set-tools">
                                @foreach ($splitForm['addable'] as $version)
                                    <x-admin.ui.button size="sm" icon="plus" wire:click="addSplitVersion({{ $version['id'] }})">Add {{ $version['label'] }}</x-admin.ui.button>
                                @endforeach
                            </div>
                        @endif

                        <p @class(['rg-admin-games__note', 'rg-admin-games__status--issue' => $splitForm['issues'] !== []]) data-testid="traffic-split-note">
                            {{ $splitForm['issues'] !== [] ? $splitForm['issues'][0] : ($splitForm['existing'] ? 'Shares add up to 100%. Saving changes this split.' : 'Shares add up to 100%. Saving starts the split.') }}
                        </p>

                        <div class="rg-admin-games__set-tools">
                            <x-admin.ui.button size="sm" :disabled="$splitForm['rows'] === []" wire:click="spreadSplitEvenly">Split evenly</x-admin.ui.button>
                            <x-admin.ui.button size="sm" wire:click="closeSplit">Cancel</x-admin.ui.button>
                            <x-admin.ui.button size="sm" variant="primary" :disabled="! $splitForm['changed'] || $splitForm['issues'] !== []" wire:click="saveSplit">Save split</x-admin.ui.button>
                        </div>
                    </div>
                </div>
            @endif
        </x-admin.ui.card>

        <x-admin.ui.card title="Periods" description="Each applied allocation starts a period, unless the current one had no visitors yet. Numbers stay with the period they were made in.">
            @if ($periods === [])
                <x-admin.ui.empty-state icon="clock" title="No periods yet">Apply shares to start the first one.</x-admin.ui.empty-state>
            @else
                <ul class="rg-admin-games__profiles rg-admin-games__card-body">
                    @foreach ($periods as $period)
                        <li class="rg-admin-games__profile">
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-games__mechanic-name">{{ $period['audience'] }} · {{ $period['shares'] }}</span>
                                <span class="rg-admin-games__note">{{ $period['from'] }} — {{ $period['to'] ?? 'now' }} · {{ number_format($period['visitors']) }} {{ \Illuminate\Support\Str::plural('visitor', $period['visitors']) }} assigned · {{ number_format($period['started']) }} started · {{ number_format($period['completed']) }} completed</span>
                            </span>
                            <x-admin.ui.badge :tone="$period['to'] === null ? 'success' : 'neutral'">{{ $period['to'] === null ? 'Current' : 'Ended' }}</x-admin.ui.badge>
                            @if ($period['resultsUrl'] !== null)
                                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$period['resultsUrl']">Results</x-admin.ui.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.ui.card>
    </div>

    @if ($ending !== null)
        <x-admin.ui.confirm-dialog id="rg-admin-game-split-end" icon="circle-stop" title="End this split?" x-on:dismiss="$wire.closeEndSplit()">
            <p>Paid clicks of {{ $ending }} go back to the next split that covers them. Visitors it already sent keep their version while it has a share anywhere in this game.</p>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="endSplit" wire:loading.attr="disabled">End split</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
