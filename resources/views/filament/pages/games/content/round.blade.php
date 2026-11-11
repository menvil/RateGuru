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
    $objective = $hasUpload || $round['mode'] === 'objective';
    $modes = [
        'crowd' => [
            'label' => 'Crowd preference',
            'help' => $hasUpload ? 'Needs two posts: an uploaded picture has no site votes.' : 'No option is correct. Players score when they pick the post with the larger share of upvotes on '.$siteName.'.',
            'disabled' => $hasUpload,
        ],
        'objective' => [
            'label' => 'Objective answer',
            'help' => $hasUpload ? 'Required: an uploaded picture has no site votes, so mark which option is correct.' : 'One option is correct. Players score when they pick it. Site votes aren’t used.',
            'disabled' => false,
        ],
    ];
@endphp

{{-- Keyed by place as well: a round that moves is drawn afresh, so its fields write to where it now stands. --}}
<div class="rg-admin-games__round" wire:key="round-editor-{{ $round['key'] }}-{{ $index }}">
    <div class="rg-admin-games__round-head">
        <span class="rg-admin-games__eyebrow">Round {{ $index + 1 }} of {{ count($rounds) }}</span>
        <div class="rg-admin-games__round-nav">
            @if ($liveUrl !== null)
                <x-admin.ui.button class="rg-admin-games__live-open" size="sm" icon="eye" x-on:click="preview = true">Preview round</x-admin.ui.button>
            @endif
            <x-admin.ui.icon-button icon="chevron-up" label="Previous round" variant="ghost" size="sm" :disabled="$index === 0" wire:click="selectRound({{ $index - 1 }})" />
            <x-admin.ui.icon-button icon="chevron-down" label="Next round" variant="ghost" size="sm" :disabled="$index >= count($rounds) - 1" wire:click="selectRound({{ $index + 1 }})" />
        </div>
    </div>

    @if ($issues !== [])
        <x-admin.ui.inline-notice tone="warning"><strong>Not ready to publish.</strong> {{ implode(' · ', $issues) }}.</x-admin.ui.inline-notice>
    @endif

    <x-admin.ui.card :flush="true">
        <div class="rg-admin-games__form">
            <x-admin.ui.text-field label="Round name" id="rg-admin-round-name" :value="$round['internal_name']" :error="$errors->first($path.'.internal_name') ?: null" hint="Admin only. Shown in this list and in analytics." wire:model.live.debounce.400ms="{{ $path }}.internal_name" />
            <x-admin.ui.textarea label="Question" id="rg-admin-round-question" :value="$round['question']" :rows="1" :limit="$limits['question']" :required="false" placeholder="Which one would you rather see in your feed?" :error="$errors->first($path.'.question') ?: null" wire:model.live.debounce.400ms="{{ $path }}.question" />
            <x-admin.ui.textarea label="Description" id="rg-admin-round-description" :value="$round['description']" :rows="2" :limit="$limits['description']" :required="false" placeholder="One line of context under the question…" :error="$errors->first($path.'.description') ?: null" wire:model.live.debounce.400ms="{{ $path }}.description" />

            <fieldset class="rg-admin-games__fieldset">
                <legend class="rg-admin-label">Round mode</legend>
                <div class="rg-admin-games__modes">
                    @foreach ($modes as $value => $mode)
                        <label @class(['rg-admin-games__choice', 'rg-admin-games__choice--on' => ($value === 'objective') === $objective, 'rg-admin-games__choice--disabled' => $mode['disabled']])>
                            <input type="radio" value="{{ $value }}" wire:model.live="{{ $path }}.mode" @checked(($value === 'objective') === $objective) @disabled($mode['disabled']) />
                            <span>
                                <span class="rg-admin-games__choice-title">{{ $mode['label'] }}</span>
                                <span class="rg-admin-games__note">{{ $mode['help'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-admin.ui.switch label="Play without points" id="rg-admin-round-unscored" :checked="(bool) $round['unscored']" on-hint="This round counts toward progress but not the score." off-hint="This round awards points." wire:model.live="{{ $path }}.unscored" />
        </div>
    </x-admin.ui.card>

    <div class="rg-admin-games__options">
        @foreach ($options as $option)
            @include('filament.pages.games.content.option', ['option' => $option, 'index' => $index, 'path' => $path])
        @endforeach
    </div>

    <x-admin.ui.card :flush="true">
        <div class="rg-admin-games__form">
            <x-admin.ui.textarea label="Reveal message" id="rg-admin-round-reveal" :value="$round['reveal_message']" :rows="2" :limit="$limits['reveal']" :required="false" placeholder="Shown after the player picks…" :error="$errors->first($path.'.reveal_message') ?: null" wire:model.live.debounce.400ms="{{ $path }}.reveal_message" />
            <x-admin.ui.switch label="Show each post’s upvote share" id="rg-admin-round-show-crowd" :checked="(bool) $round['show_crowd']" :on-hint="'After picking, players see each post’s share of upvotes on '.$siteName.' — not how other players picked.'" off-hint="Players see only whether they matched." wire:model.live="{{ $path }}.show_crowd" />

            @if ($crowd !== null && ! $crowd['unscored'] && $roundCheck?->mode?->value === 'crowd')
                @php
                    $ready = $crowd['state'] === 'majority';
                @endphp
                <div class="rg-admin-games__crowd">
                    <div class="rg-admin-games__crowd-head">
                        <span class="rg-admin-games__eyebrow">Crowd pick</span>
                        <x-admin.ui.badge :tone="$ready ? 'success' : 'danger'">{{ $ready ? 'Ready' : 'Cannot be published' }}</x-admin.ui.badge>
                    </div>

                    <div class="rg-admin-games__crowd-posts">
                        <span class="rg-admin-games__crowd-caption">Each post’s share of upvotes on {{ $siteName }}</span>
                        @foreach (['a' => 0, 'b' => 1] as $side => $slot)
                            @php
                                $post = $options[$slot]['post'] ?? null;
                                $winner = $crowd['preferred'] === $slot;
                            @endphp
                            <div @class(['rg-admin-games__crowd-row', 'rg-admin-games__crowd-row--winner' => $winner])>
                                <span class="rg-admin-games__option-key">{{ strtoupper($side) }}</span>
                                <span class="rg-admin-games__crowd-post">
                                    <span class="rg-admin-games__crowd-name">{{ $post === null ? 'No post chosen' : ($post['title'] !== '' ? $post['title'] : 'Post #'.$post['id']) }}</span>
                                    <span class="rg-admin-games__note">{{ $post === null ? 'Choose a post for option '.strtoupper($side) : '#'.$post['id'].($post['author'] ? ' · @'.$post['author'] : '') }}</span>
                                    <span class="rg-admin-games__crowd-bar" aria-hidden="true"><span style="width: {{ (float) ($crowd[$side]['share'] ?? 0) }}%"></span></span>
                                </span>
                                <span class="rg-admin-games__crowd-figure">
                                    <span class="rg-admin-games__crowd-share">{{ $crowd[$side]['share'] !== null ? $crowd[$side]['share'].'%' : '—' }}</span>
                                    <span @class(['rg-admin-games__note', 'rg-admin-games__crowd-short' => $crowd[$side]['votes'] < $crowd['minVotes']])>{{ number_format($crowd[$side]['votes']) }} votes</span>
                                </span>
                            </div>
                        @endforeach
                        <span class="rg-admin-games__note">The post with the larger share of upvotes is the crowd pick. Each post needs at least {{ number_format($crowd['minVotes']) }} votes{{ $crowd['minGap'] > 0 ? ' and the shares a gap of '.$crowd['minGap'].' points' : '' }}, set in Results.</span>
                    </div>

                    @php
                        // What can be done about the pick, and to which option: the post at fault, or either
                        // one when neither is — two shares too close to call are both the cause.
                        $letters = [0 => 'A', 1 => 'B'];
                        $faults = array_values(array_filter([0, 1], fn (int $slot): bool => in_array($options[$slot]['problem'] ?? null, ['gone', 'picture'], true)));
                        $replace = match ($crowd['state']) {
                            'ineligible' => $faults,
                            'insufficient' => $crowd['short'],
                            'tie', 'ambiguous' => [0, 1],
                            default => [],
                        };
                    @endphp
                    <div class="rg-admin-games__crowd-verdict">
                        <x-admin.ui.inline-notice :tone="$ready ? 'success' : 'warning'">
                            @switch ($crowd['state'])
                                @case('majority') Players score when they pick option {{ $crowd['preferred'] === 0 ? 'A' : 'B' }}. @break
                                @case('tie') Both posts have exactly the same share of upvotes. With no crowd pick, no point can be awarded. Replace either post, or use a correct answer. @break
                                @case('ambiguous') The shares differ by less than {{ $crowd['minGap'] }} points, which this set treats as too close to call. It is a product rule, not a statistical test. Replace either post, or use a correct answer. @break
                                @case('insufficient')
                                    @foreach ($crowd['short'] as $slot)
                                        Option {{ $letters[$slot] }}’s post has {{ number_format($crowd[strtolower($letters[$slot])]['votes']) }} site {{ \Illuminate\Support\Str::plural('vote', $crowd[strtolower($letters[$slot])]['votes']) }}; each post needs at least {{ number_format(max(1, $crowd['minVotes'])) }} before the crowd decides a point.
                                    @endforeach
                                    @break
                                @case('ineligible')
                                    @foreach ([0, 1] as $slot)
                                        @switch ($options[$slot]['problem'] ?? null)
                                            @case('gone') Option {{ $letters[$slot] }}’s post is no longer on the site. @break
                                            @case('picture') Option {{ $letters[$slot] }}’s post has no picture that can be shown. @break
                                            @case('processing') Option {{ $letters[$slot] }}’s picture is still being prepared; the round is checked again once it is ready. @break
                                        @endswitch
                                    @endforeach
                                    @break
                                @default Choose a post for both options. The crowd pick comes from their upvotes on {{ $siteName }}.
                            @endswitch
                        </x-admin.ui.inline-notice>
                        @if ($replace !== [] || in_array($crowd['state'], ['insufficient', 'tie', 'ambiguous'], true))
                            <div class="rg-admin-games__set-tools">
                                @foreach ($replace as $slot)
                                    <x-admin.ui.button size="sm" icon="newspaper" wire:click="openPicker({{ $index }}, {{ $slot }})">Choose another post for {{ $letters[$slot] }}</x-admin.ui.button>
                                @endforeach
                                @if (in_array($crowd['state'], ['insufficient', 'tie', 'ambiguous'], true))
                                    <x-admin.ui.button size="sm" icon="circle-check" wire:click="useCorrectAnswer({{ $index }})">Use a correct answer</x-admin.ui.button>
                                @endif
                            </div>
                        @endif
                    </div>

                    <p class="rg-admin-games__crowd-freeze"><x-admin.ui.icon name="lock" :size="13" /> Publishing freezes both posts’ votes into the version, so past results stay the same when site votes change.</p>
                </div>
            @endif
        </div>
    </x-admin.ui.card>
</div>
