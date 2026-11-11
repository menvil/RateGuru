@php
    /*
     * The set's Results: how its rounds score — points per match and how sure
     * the crowd must be — and its
     * result profiles, each the numbers of matches it stands for, with what
     * keeps them from covering every result exactly once.
     */
    $scored = $checks->scoredRounds;
    $points = max(1, (int) ($scoring['points_per_round'] ?? 1));
    $crowdRounds = collect($checks->rounds)->filter(fn ($check) => $check->scored && $check->mode->value === 'crowd')->count();
    $objectiveRounds = collect($checks->rounds)->filter(fn ($check) => $check->scored && $check->mode->value === 'objective')->count();
    $unscoredRounds = count($checks->rounds) - $scored;
    $missingShare = collect($profileRows)->reject(fn ($row) => $row['hasShareImage'])->pluck('name')->all();
@endphp

<div class="rg-admin-games__round">
    <x-admin.ui.card title="Scoring" description="Results belong to this set. Profiles count matches: how many scored rounds a player got right.">
        <div class="rg-admin-games__form">
            <dl class="rg-admin-detail-list">
                <div class="rg-admin-detail-row"><dt class="rg-admin-detail-row__label">Scored by the crowd’s site votes</dt><dd class="rg-admin-detail-row__value">{{ $crowdRounds }}</dd></div>
                <div class="rg-admin-detail-row"><dt class="rg-admin-detail-row__label">Scored by a correct answer</dt><dd class="rg-admin-detail-row__value">{{ $objectiveRounds }}</dd></div>
                <div class="rg-admin-detail-row"><dt class="rg-admin-detail-row__label">Without points</dt><dd class="rg-admin-detail-row__value">{{ $unscoredRounds }}</dd></div>
                <div class="rg-admin-detail-row"><dt class="rg-admin-detail-row__label">Maximum score</dt><dd class="rg-admin-detail-row__value">{{ $scored * $points }} ({{ $scored }} × {{ $points }} {{ \Illuminate\Support\Str::plural('point', $points) }})</dd></div>
            </dl>
            <x-admin.ui.number-stepper label="Points per match" id="rg-admin-set-points" :value="$scoring['points_per_round']" :min="1" :max="10" :error="$errors->first('scoring.points_per_round') ?: null" wire:model.live="scoring.points_per_round" />
            <x-admin.ui.number-stepper label="Site votes each post needs" id="rg-admin-set-min-votes" :value="$scoring['crowd_min_votes']" :min="1" :max="1000000" hint="A crowd round scores only when both posts have at least this many up and down votes on the site." :error="$errors->first('scoring.crowd_min_votes') ?: null" wire:model.live.debounce.400ms="scoring.crowd_min_votes" />
            <x-admin.ui.number-stepper label="Gap the crowd pick needs" id="rg-admin-set-min-gap" :value="$scoring['crowd_min_gap']" :min="0" :max="50" unit="points" hint="Posts whose upvote shares differ by less are too close to call: a product rule, not a statistical test. At 0 the larger share wins however small its lead; only a tie has no pick." :error="$errors->first('scoring.crowd_min_gap') ?: null" wire:model.live.debounce.400ms="scoring.crowd_min_gap" />
        </div>
    </x-admin.ui.card>

    <x-admin.ui.card title="Result profiles" :description="'Every number of matches from 0 to '.$scored.' needs exactly one profile.'">
        <x-slot:aside>
            <x-admin.ui.button size="sm" icon="plus" wire:click="addProfile">Add profile</x-admin.ui.button>
        </x-slot:aside>

        <div class="rg-admin-games__form">
            @if ($checks->profileIssues !== [])
                <x-admin.ui.inline-notice tone="warning">
                    Score ranges need fixing before publish: {{ implode(' ', $checks->profileIssues) }}
                    @if ($profileRows !== [])
                        <x-slot:actions>
                            <x-admin.ui.button size="sm" wire:click="fitRanges">Fit ranges</x-admin.ui.button>
                        </x-slot:actions>
                    @endif
                </x-admin.ui.inline-notice>
            @endif

            @if ($profileRows !== [] && $missingShare !== [])
                <p class="rg-admin-games__note">{{ count($missingShare) }} of {{ count($profileRows) }} profiles have no share image: {{ implode(', ', $missingShare) }}. They use the game’s cover. Publishing isn’t blocked.</p>
            @endif

            @if ($profileRows === [])
                <x-admin.ui.empty-state icon="award" title="No result profiles yet">
                    A profile is what a player is told they are, by how many rounds they matched.
                    <x-slot:action>
                        <x-admin.ui.button size="sm" variant="primary" wire:click="addSuggestedProfiles">Add suggested profiles</x-admin.ui.button>
                    </x-slot:action>
                </x-admin.ui.empty-state>
            @else
                <ul class="rg-admin-games__profiles">
                    @foreach ($profileRows as $row)
                        <li class="rg-admin-games__profile" wire:key="profile-{{ $row['key'] }}">
                            <span class="rg-admin-games__profile-range">{{ $row['range'] }}</span>
                            <span class="rg-admin-games__mechanic-icon" aria-hidden="true"><x-admin.ui.icon :name="$row['icon']" :size="16" /></span>
                            <span class="rg-admin-games__mechanic-text">
                                <span class="rg-admin-games__mechanic-name">{{ $row['name'] }}</span>
                                <span class="rg-admin-games__note">{{ $row['description'] }}@if ($row['points']) · {{ $row['points'] }}@endif</span>
                                @if ($row['shareText'] !== '')
                                    <span class="rg-admin-games__note">Share: {{ $row['shareText'] }}</span>
                                @endif
                            </span>
                            <x-admin.ui.button size="sm" wire:click="editProfile('{{ $row['key'] }}')">Edit<span class="rg-admin-sr-only"> {{ $row['name'] }}</span></x-admin.ui.button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-admin.ui.card>
</div>
