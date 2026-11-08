@php
    /*
     * The round on screen: its name, question and description; how it scores
     * — by the crowd's site votes or by a correct answer — or that it plays
     * without points; its two options; what players are told after the pick;
     * and, for a crowd round, the crowd pick as the site's votes stand now,
     * with what keeps it from being published.
     */
    $index = $selected;
    $path = "rounds.{$index}";
    $hasUpload = collect($options)->contains(fn (array $option): bool => $option['source'] === 'upload');
    $issues = $roundCheck?->issues ?? [];
@endphp

<div class="rg-admin-games__round" wire:key="round-editor-{{ $round['key'] }}">
    <div class="rg-admin-games__round-head">
        <span class="rg-admin-games__note">Round {{ $index + 1 }} of {{ count($rounds) }}</span>
        <div class="rg-admin-games__round-nav">
            <x-admin.ui.icon-button icon="chevron-left" label="Previous round" variant="ghost" size="sm" :disabled="$index === 0" wire:click="selectRound({{ $index - 1 }})" />
            <x-admin.ui.icon-button icon="chevron-right" label="Next round" variant="ghost" size="sm" :disabled="$index >= count($rounds) - 1" wire:click="selectRound({{ $index + 1 }})" />
        </div>
    </div>

    @if ($issues !== [])
        <x-admin.ui.inline-notice tone="warning">Not ready to publish. {{ implode(' · ', $issues) }}.</x-admin.ui.inline-notice>
    @endif

    <x-admin.ui.card title="Round" description="The round name is for admins; players see the question and the two pictures.">
        <div class="rg-admin-games__form">
            <x-admin.ui.text-field label="Round name" id="rg-admin-round-name" :value="$round['internal_name']" :error="$errors->first($path.'.internal_name') ?: null" hint="Only admins see it — in this list and in analytics." wire:model.live.debounce.400ms="{{ $path }}.internal_name" />
            <x-admin.ui.textarea label="Question" id="rg-admin-round-question" :value="$round['question']" :rows="2" :limit="$limits['question']" :required="false" placeholder="Which one would you rather see in your feed?" :error="$errors->first($path.'.question') ?: null" wire:model.live.debounce.400ms="{{ $path }}.question" />
            <x-admin.ui.textarea label="Description" id="rg-admin-round-description" :value="$round['description']" :rows="2" :limit="$limits['description']" :required="false" :error="$errors->first($path.'.description') ?: null" wire:model.live.debounce.400ms="{{ $path }}.description" />

            <fieldset class="rg-admin-games__fieldset">
                <legend class="rg-admin-label">How the round scores</legend>
                <label class="rg-admin-games__choice">
                    <input type="radio" value="crowd" wire:model.live="{{ $path }}.mode" @disabled($hasUpload) />
                    <span>
                        <span class="rg-admin-games__choice-title">Crowd preference</span>
                        <span class="rg-admin-games__note">{{ $hasUpload ? 'Needs two posts: an uploaded picture has no site votes.' : 'No option is correct. Players score when they pick the post with the larger share of upvotes on '.$siteName.'.' }}</span>
                    </span>
                </label>
                <label class="rg-admin-games__choice">
                    <input type="radio" value="objective" wire:model.live="{{ $path }}.mode" @checked($hasUpload || $round['mode'] === 'objective') />
                    <span>
                        <span class="rg-admin-games__choice-title">Objective answer</span>
                        <span class="rg-admin-games__note">{{ $hasUpload ? 'Required: an uploaded picture has no site votes, so mark which option is correct.' : 'One option is correct. Players score when they pick it. Site votes aren’t used.' }}</span>
                    </span>
                </label>
                <x-admin.ui.switch label="Play without points" id="rg-admin-round-unscored" :checked="(bool) $round['unscored']" on-hint="This round counts toward progress but not the score." off-hint="This round awards points." wire:model.live="{{ $path }}.unscored" />
            </fieldset>
        </div>
    </x-admin.ui.card>

    <div class="rg-admin-games__options">
        @foreach ($options as $option)
            @include('filament.pages.games.content.option', ['option' => $option, 'index' => $index, 'path' => $path])
        @endforeach
    </div>

    <x-admin.ui.card title="After the pick" description="What players see once they have chosen.">
        <div class="rg-admin-games__form">
            <x-admin.ui.textarea label="Reveal message" id="rg-admin-round-reveal" :value="$round['reveal_message']" :rows="2" :limit="$limits['reveal']" :required="false" placeholder="Most people picked the beach." :error="$errors->first($path.'.reveal_message') ?: null" wire:model.live.debounce.400ms="{{ $path }}.reveal_message" />
            <x-admin.ui.switch label="Show each post’s upvote share" id="rg-admin-round-show-crowd" :checked="(bool) $round['show_crowd']" :on-hint="'After picking, players see each post’s share of upvotes on '.$siteName.' — not how other players picked.'" off-hint="Players see only whether they matched." wire:model.live="{{ $path }}.show_crowd" />
        </div>

        @if ($crowd !== null && ! $crowd['unscored'] && $roundCheck?->mode?->value === 'crowd')
            <div class="rg-admin-games__crowd">
                <div class="rg-admin-games__crowd-head">
                    <span class="rg-admin-label">Crowd pick</span>
                    @if ($crowd['state'] === 'majority')
                        <x-admin.ui.badge tone="success">Ready</x-admin.ui.badge>
                    @else
                        <x-admin.ui.badge tone="danger">Cannot be published</x-admin.ui.badge>
                    @endif
                </div>
                @foreach (['a' => 0, 'b' => 1] as $side => $slot)
                    <div class="rg-admin-games__crowd-row">
                        <span class="rg-admin-games__crowd-name">Option {{ strtoupper($side) }}@if ($crowd['preferred'] === $slot) · crowd’s pick @endif</span>
                        <span class="rg-admin-games__crowd-bar" aria-hidden="true"><span style="width: {{ (float) ($crowd[$side]['share'] ?? 0) }}%"></span></span>
                        <span class="rg-admin-games__crowd-figure">{{ $crowd[$side]['share'] !== null ? $crowd[$side]['share'].'% upvotes' : 'No votes' }} · {{ number_format($crowd[$side]['votes']) }} votes</span>
                    </div>
                @endforeach
                <p class="rg-admin-games__note">
                    @switch ($crowd['state'])
                        @case('majority') Players score when they pick option {{ $crowd['preferred'] === 0 ? 'A' : 'B' }}. Publishing freezes both posts’ votes into the version, so past results stay the same when site votes change. @break
                        @case('tie') Both posts have exactly the same share of upvotes. With no crowd pick, no point can be awarded. @break
                        @case('ambiguous') The shares differ by less than {{ $crowd['minGap'] }} points, which this set treats as too close to call. It is a product rule, not a statistical test. @break
                        @case('insufficient') Each post needs at least {{ number_format($crowd['minVotes']) }} site votes before the crowd decides a point. @break
                        @default Choose a post for both options. The crowd pick comes from their upvotes on {{ $siteName }}.
                    @endswitch
                </p>
                @if ($crowd['state'] !== 'majority' && $crowd['state'] !== 'missing')
                    <div class="rg-admin-games__set-tools">
                        <x-admin.ui.button size="sm" wire:click="openPicker({{ $index }}, {{ $crowd['state'] === 'insufficient' && ($crowd['a']['votes'] < $crowd['minVotes']) ? 0 : 1 }})">Choose another post</x-admin.ui.button>
                        <x-admin.ui.button size="sm" wire:click="useCorrectAnswer({{ $index }})">Use a correct answer</x-admin.ui.button>
                    </div>
                @endif
            </div>
        @endif
    </x-admin.ui.card>
</div>
