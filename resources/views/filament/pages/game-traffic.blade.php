@php
    /*
     * A game's Traffic tab (GameTraffic): each audience's shares between the
     * active versions, applied on its own, and the periods allocations were
     * in effect, newest first, each with the visitors it assigned.
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
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-admin.ui.card>
    </div>
</div>
