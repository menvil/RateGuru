{{--
    The Post step's Votes tab (StudioPlanPostVotesEditor), laid out as the
    prototype's StudioPostVotes: the post's voters on the left, and the Post
    votes card on the right — how many vote the post up and down and how many
    answer each rating question, the voters those make, and when, each vote's
    moment set by hand on the Timing tab. Every change is saved at once; under
    the card, the strip says what is saved, or what keeps a change from being
    saved. Every text is drawn escaped.
--}}
@php
    $savedCount = count($rows);
    $short = max(0, $need - $voters);
    $answers = 0;
    $asked = 0;
    $open = 0;

    foreach ($questions as $question) {
        $open += $question['open'] ? 1 : 0;
        $total = array_sum($ratings[$question['id']]['counts'] ?? []);
        $asked += $question['open'] && $total > 0 ? 1 : 0;
        $answers += $total;
    }

    $score = $postOn ? $up - $down : 0;
    $savedUp = count(array_filter($rows, fn (array $row): bool => $row['vote'] === 'up'));
    $savedDown = count(array_filter($rows, fn (array $row): bool => $row['vote'] === 'down'));
    $savedAnswers = $savedCount - $savedUp - $savedDown;
    $blocked = count(array_filter($rows, fn (array $row): bool => $row['problem'] !== null));
    $status = match (true) {
        $unsaved => 'Not saved · fix what is marked, or Discard to go back to the votes saved.',
        $savedCount > 0 => $savedCount.' '.($savedCount === 1 ? 'vote' : 'votes').' saved · +'.$savedUp.' / −'.$savedDown.' on the post · '.$savedAnswers.' '.($savedAnswers === 1 ? 'answer' : 'answers')
            .($blocked > 0 ? ' · '.$blocked.' can’t be cast as '.($blocked === 1 ? 'it is' : 'they are').' — see the Timing tab' : ''),
        default => 'No votes prepared · the post gets only real users’ votes.',
    };
    $authorChanged = '($store.adminEditor?.authorChanged ?? false)';
@endphp

<div class="rg-admin-studio__votes" x-data x-effect="$wire.authorChanged = {{ $authorChanged }}">
    @if ($gone)
        <x-admin.ui.inline-notice tone="warning">This draft was deleted, so its votes can’t be changed any more.</x-admin.ui.inline-notice>
    @elseif ($closed)
        <x-admin.ui.inline-notice tone="info">This plan is no longer a draft, so its votes can’t be changed here.</x-admin.ui.inline-notice>
    @elseif ($conflict)
        <x-admin.ui.inline-notice tone="warning">
            Someone changed this draft after you opened it, so nothing here is saved until you reload it.
            <x-slot:actions>
                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload votes</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.inline-notice>
    @endif

    <div x-data x-show="{{ $authorChanged }}" x-cloak>
        <x-admin.ui.inline-notice tone="warning">The post’s author has unsaved changes. Save the draft, then prepare the votes: they are made for the author the draft has saved.</x-admin.ui.inline-notice>
    </div>

    <div class="rg-admin-studio__votes-layout">
        {{-- Post voters (the prototype's VoterSelect, `which="post"`). --}}
        <div class="rg-admin-studio__votes-side">
            <x-admin.ui.card title="Post voters" description="Personas who vote on the post and answer its questions. Each votes once.">
                <div class="rg-admin-studio__discussion-fields">
                    <div class="rg-admin-studio__votes-author">
                        @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $author])
                        <span class="rg-admin-studio__votes-author-name">
                            @if ($author !== null)
                                <span class="rg-admin-studio__persona-name">{{ $author['name'] }}</span> <span class="rg-admin-studio__muted">· author</span>
                            @else
                                <span class="rg-admin-studio__muted">No author saved yet</span>
                            @endif
                        </span>
                        @if ($author !== null)
                            <span class="rg-admin-studio__muted rg-admin-studio__votes-author-note">Can’t vote on own post</span>
                        @endif
                    </div>

                    <x-admin.ui.segmented label="How voters are chosen" :options="['random' => 'Random', 'manual' => 'Choose']" :value="$voterMode" wire:model.live="voterMode" class="rg-admin-studio__discussion-mode" />

                    @if ($voterMode === 'random')
                        <div class="rg-admin-studio__votes-random">
                            <x-admin.ui.number-stepper
                                label="Number of personas"
                                id="rg-admin-votes-voter-count"
                                :value="$voterCount"
                                :min="0"
                                :max="$cap"
                                :unit="'of '.$cap"
                                :error="$errors->first('voterCount') ?: null"
                                wire:model.live.debounce.400ms="voterCount"
                            />
                            <x-admin.ui.button size="sm" icon="shuffle" :disabled="! $editable" wire:click="redraw">Pick others</x-admin.ui.button>
                        </div>
                        <p class="rg-admin-hint">Random from the active personas. Pick others keeps the number and draws different ones, saved at once.</p>
                    @else
                        <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="voterSearch" autocomplete="off" />
                        <div class="rg-admin-studio__discussion-count">
                            <span>{{ $voters }} selected of {{ $cap }}</span>
                            <button type="button" class="rg-admin-studio__text-button" wire:click="selectShown" @disabled(! $editable)>{{ trim($voterSearch) !== '' ? 'Select shown' : 'Select all' }}</button>
                            <button type="button" class="rg-admin-studio__text-button" wire:click="clearVoters" @disabled(! $editable || $chosen === [])>Clear</button>
                        </div>
                        @if ($chosen !== [])
                            <div class="rg-admin-studio__chips">
                                @foreach ($chosen as $persona)
                                    <span class="rg-admin-studio__chip rg-admin-studio__chip--removable" wire:key="votes-chosen-{{ $persona['id'] }}">
                                        {{ $persona['username'] ?? $persona['name'] }}
                                        @if (($persona['problem'] ?? null) === 'deleted')
                                            <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
                                        @elseif (($persona['problem'] ?? null) === 'not_persona')
                                            <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
                                        @elseif (($persona['problem'] ?? null) === 'restricted' && ($persona['status'] ?? null) !== null)
                                            <x-admin.ui.badge :tone="$persona['status']['tone']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
                                        @elseif ($persona['id'] === $authorId)
                                            <x-admin.ui.badge tone="neutral">Author</x-admin.ui.badge>
                                        @endif
                                        <button type="button" class="rg-admin-studio__chip-remove" aria-label="Remove {{ $persona['name'] }}" wire:click="toggleVoter({{ $persona['id'] }})" @disabled(! $editable)>
                                            <x-admin.ui.icon name="x" :size="11" />
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if (collect($chosen)->contains(fn (array $persona): bool => ($persona['problem'] ?? null) !== null || $persona['id'] === $authorId))
                            <p class="rg-admin-hint rg-admin-studio__votes-warning-text">A deleted or restricted persona, or the post’s author, gets no new votes. A restricted one keeps a vote it has until you replace it.</p>
                        @endif
                        <div class="rg-admin-studio__persona-list" role="group" aria-label="Personas to choose">
                            @forelse ($listed as $persona)
                                <button
                                    type="button"
                                    role="checkbox"
                                    aria-checked="{{ $persona['on'] && ! $persona['author'] ? 'true' : 'false' }}"
                                    @if ($persona['author']) aria-disabled="true" @endif
                                    @class(['rg-admin-studio__persona-option', 'rg-admin-studio__persona-option--disabled' => $persona['author']])
                                    wire:key="votes-persona-{{ $persona['id'] }}"
                                    @unless ($persona['author']) wire:click="toggleVoter({{ $persona['id'] }})" @endunless
                                    @disabled(! $editable)
                                >
                                    <span class="rg-admin-studio__check" aria-hidden="true">
                                        @if ($persona['on'] && ! $persona['author'])
                                            <x-admin.ui.icon name="check" :size="12" />
                                        @endif
                                    </span>
                                    @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $persona])
                                    <span class="rg-admin-studio__persona-text">
                                        <span class="rg-admin-studio__persona-name">{{ $persona['name'] }} <span class="rg-admin-studio__muted">{{ $persona['username'] ?? '' }}</span></span>
                                        <span class="rg-admin-studio__persona-short">{{ $persona['author'] ? 'Author of this post · can’t vote on it' : $persona['short'] }}</span>
                                    </span>
                                </button>
                            @empty
                                <p class="rg-admin-hint rg-admin-studio__persona-none">{{ trim($voterSearch) !== '' ? 'No personas match “'.$voterSearch.'”.' : 'There are no active personas.' }}</p>
                            @endforelse
                        </div>
                    @endif

                    <p class="rg-admin-hint">{{ $voters }} {{ $voters === 1 ? 'persona' : 'personas' }} can vote. Up and down votes together, and the answers to each question, can’t go above {{ $voters }}.</p>
                    @if ($short > 0)
                        <div class="rg-admin-studio__votes-warning" role="status">
                            <x-admin.ui.icon name="circle-alert" :size="15" />
                            <span>These votes need {{ $need }} {{ $need === 1 ? 'persona' : 'personas' }}; {{ $voters }} {{ $voters === 1 ? 'is' : 'are' }} chosen — {{ $short }} short. Choose more personas, or ask for fewer votes.</span>
                        </div>
                    @endif
                    @error('voters')
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                    @enderror
                </div>
            </x-admin.ui.card>
        </div>

        <div class="rg-admin-studio__votes-main">
            <x-admin.ui.card title="Post votes" description="Reactions to the post itself: up and down votes and answers to its questions. Comments and their votes are set in Discussion." flush>
                <div class="rg-admin-studio__votes-stats">
                    <div class="rg-admin-studio__votes-stat">
                        <span class="rg-admin-overline">Up · down</span>
                        <span class="rg-admin-studio__votes-stat-value">{{ $postOn ? '+'.$up.' / −'.$down : 'Off' }}</span>
                        <span class="rg-admin-studio__votes-stat-note">{{ $postOn ? 'Score '.($score >= 0 ? '+' : '').$score : 'Real votes only' }}</span>
                    </div>
                    <div class="rg-admin-studio__votes-stat">
                        <span class="rg-admin-overline">Answers</span>
                        <span class="rg-admin-studio__votes-stat-value">{{ $answers }}</span>
                        <span class="rg-admin-studio__votes-stat-note">{{ $asked }} of {{ $open }} open {{ $open === 1 ? 'question' : 'questions' }}</span>
                    </div>
                    <div class="rg-admin-studio__votes-stat">
                        <span class="rg-admin-overline">Post voters</span>
                        <span class="rg-admin-studio__votes-stat-value">{{ $voters }}</span>
                        <span class="rg-admin-studio__votes-stat-note">of {{ $cap }} personas who can vote</span>
                    </div>
                </div>

                <x-admin.ui.tabs label="Post votes" :active="$tab" class="rg-admin-studio__votes-tabs" :items="[
                    ['id' => 'votes', 'label' => 'Votes & answers', 'attributes' => ['wire:click' => '$set(\'tab\', \'votes\')']],
                    ['id' => 'voters', 'label' => 'Voters', 'count' => $voterTable['voting'], 'attributes' => ['wire:click' => '$set(\'tab\', \'voters\')']],
                    ['id' => 'timing', 'label' => 'Timing', 'attributes' => ['wire:click' => '$set(\'tab\', \'timing\')']],
                ]" />

                @if ($tab === 'voters')
                    <div class="rg-admin-studio__votes-toolbar">
                        <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="votersSearch" autocomplete="off" class="rg-admin-studio__votes-search" />
                        <x-admin.ui.segmented label="Show" :options="['all' => 'All', 'voting' => 'Voting', 'idle' => 'Not used']" :value="$votersFilter" wire:model.live="votersFilter" />
                        <span class="rg-admin-studio__muted rg-admin-studio__votes-toolbar-count">{{ $voterTable['pool'] }} post voters · {{ $voterTable['voting'] }} vote or answer</span>
                        <x-admin.ui.button size="sm" icon="shuffle" :disabled="! $editable" wire:click="redraw">Reshuffle</x-admin.ui.button>
                    </div>
                    <p class="rg-admin-hint rg-admin-studio__votes-pad">The votes as saved. Reshuffle draws other voters and assignments with the same numbers, and saves them at once.</p>
                    <div class="rg-admin-studio__timing-scroll">
                        <table class="rg-admin-studio__timing rg-admin-studio__votes-table">
                            <thead>
                                <tr>
                                    <th scope="col">Persona</th>
                                    <th scope="col">Post vote</th>
                                    @foreach ($voterTable['questions'] as $question)
                                        <th scope="col">{{ $question['label'] }} answer</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($voterTable['rows'] as $row)
                                    <tr @class(['rg-admin-studio__votes-idle' => ! $row['voting']]) wire:key="votes-voter-{{ $row['persona']['id'] ?? $loop->index }}">
                                        <td>
                                            <span class="rg-admin-studio__votes-who">
                                                @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $row['persona']])
                                                <span class="rg-admin-studio__persona-text">
                                                    <span class="rg-admin-studio__persona-name">{{ $row['persona']['name'] ?? 'Deleted user' }}</span>
                                                    <span class="rg-admin-studio__persona-short">{{ isset($row['persona']['username']) ? '@'.$row['persona']['username'] : '' }}</span>
                                                </span>
                                            </span>
                                        </td>
                                        <td>@include('livewire.admin.studio-plan-post-vote-cell', ['vote' => $row['post']])</td>
                                        @foreach ($voterTable['questions'] as $question)
                                            <td @class(['rg-admin-studio__muted' => ! isset($row['answers'][$question['id']])])>{{ $row['answers'][$question['id']] ?? '—' }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ 2 + count($voterTable['questions']) }}" class="rg-admin-studio__muted">No personas match.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @elseif ($tab === 'timing')
                    <div class="rg-admin-studio__votes-body">
                        <p class="rg-admin-hint">Counted from the moment the post is published. Nothing can arrive before it. Comments have their own timing in Discussion.</p>
                        <div class="rg-admin-studio__votes-blocks">
                            <div class="rg-admin-studio__votes-block">
                                <div class="rg-admin-studio__votes-block-head">
                                    <span class="rg-admin-studio__persona-name">Voters come</span>
                                    <span class="rg-admin-studio__muted">{{ $timing['meta'] }}</span>
                                    @if ($timing['byHand'])
                                        <x-admin.ui.badge tone="outline">Set by hand</x-admin.ui.badge>
                                    @endif
                                </div>
                                <p class="rg-admin-hint">Each voter comes once: votes on the post, then answers the questions, a minute or two apart.</p>
                                @foreach (['first' => 'First vote after', 'spread' => 'Spread over'] as $field => $label)
                                    <div class="rg-admin-studio__votes-chips" role="group" aria-label="{{ $label }}">
                                        <span class="rg-admin-studio__votes-chips-label">{{ $label }}</span>
                                        @foreach ($timing[$field] as $chip)
                                            <button type="button" class="rg-admin-studio__votes-chip" aria-pressed="{{ $chip['on'] ? 'true' : 'false' }}" wire:click="setTiming('{{ $field }}', {{ $chip['seconds'] }})" @disabled(! $editable)>{{ $chip['label'] }}</button>
                                        @endforeach
                                    </div>
                                @endforeach
                                @error('timing')
                                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                        <div class="rg-admin-studio__votes-block-head">
                            <span class="rg-admin-studio__persona-name">Every vote</span>
                            <span class="rg-admin-studio__muted">{{ $savedCount }} {{ $savedCount === 1 ? 'vote' : 'votes' }} · relative to publication · a moment changed here is saved at once, and every vote keeps its own until First vote after or Spread over lays them out again</span>
                        </div>
                        @include('livewire.admin.studio-plan-post-votes-table', ['rows' => $rows, 'editable' => $editable])
                    </div>
                @else
                    <div class="rg-admin-studio__votes-body">
                        <div class="rg-admin-studio__votes-block">
                            <div class="rg-admin-studio__votes-block-head rg-admin-studio__votes-block-head--switch">
                                <span class="rg-admin-studio__persona-text">
                                    <span class="rg-admin-studio__persona-name">Up and down votes on the post</span>
                                    <span class="rg-admin-studio__persona-short">{{ $postOn ? 'Each post voter votes up or down at most once. Never before the post appears.' : 'Off. The post gets only real users’ votes.' }}</span>
                                </span>
                                <button type="button" role="switch" class="rg-admin-switch" aria-checked="{{ $postOn ? 'true' : 'false' }}" aria-label="Plan up and down votes" wire:click="$toggle('postOn')" @disabled(! $editable)>
                                    <span class="rg-admin-switch__knob" aria-hidden="true"></span>
                                </button>
                            </div>
                            @if ($postOn)
                                <div class="rg-admin-studio__votes-steppers">
                                    <x-admin.ui.number-stepper label="Up votes" id="rg-admin-votes-up" :value="$up" :min="0" :max="$cap" :error="$errors->first('up') ?: null" wire:model.live.debounce.400ms="up" />
                                    <x-admin.ui.number-stepper label="Down votes" id="rg-admin-votes-down" :value="$down" :min="0" :max="$cap" :error="$errors->first('down') ?: null" wire:model.live.debounce.400ms="down" />
                                    <div class="rg-admin-studio__votes-score">
                                        <span class="rg-admin-studio__persona-name">Score {{ $score >= 0 ? '+' : '' }}{{ $score }} · {{ $postTotal > 0 ? (int) round($up / $postTotal * 100) : 0 }}% up</span>
                                        <span class="rg-admin-studio__muted">{{ $postTotal }} of {{ $voters }} post voters · {{ $postTotal }} different personas</span>
                                    </div>
                                </div>
                                <p class="rg-admin-hint rg-admin-studio__votes-pad">Each vote is a different post voter’s, drawn at random from the {{ $voters }} on the left: who votes which way is on the Voters tab. Each voter comes once, votes on the post and answers the questions a minute or two apart; the first comes {{ \App\Livewire\Admin\StudioPlanDiscussionEditor::relative($firstAfter) }} after the post and the rest are spread over {{ \App\Livewire\Admin\StudioPlanDiscussionEditor::relative($spreadOver) }} — each vote’s moment is on the Timing tab, to change by hand.</p>
                                @error('post')
                                    <p class="rg-admin-error rg-admin-studio__votes-pad"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                                @enderror
                            @endif
                        </div>

                        <div class="rg-admin-studio__votes-block-head">
                            <span class="rg-admin-studio__persona-name">Rating groups</span>
                            <span class="rg-admin-studio__muted">The questions under this post. Each voter answers each question at most once.</span>
                        </div>
                        @if ($questions === [])
                            <p class="rg-admin-hint">There are no active rating questions.</p>
                        @endif
                        <div class="rg-admin-studio__votes-questions">
                            @foreach ($questions as $question)
                                @php
                                    $rating = $ratings[$question['id']] ?? ['counts' => [], 'first' => 0, 'spread' => 0, 'moments' => []];
                                    $total = array_sum($rating['counts']);
                                    $top = $total > 0 ? max($rating['counts']) : -1;
                                @endphp
                                <div @class(['rg-admin-studio__votes-block', 'rg-admin-studio__votes-block--off' => ! $question['open']]) wire:key="votes-question-{{ $question['id'] }}">
                                    <div class="rg-admin-studio__votes-block-head rg-admin-studio__votes-block-head--switch">
                                        <span class="rg-admin-studio__persona-text">
                                            <span class="rg-admin-studio__persona-name">{{ $question['label'] }}</span>
                                            @if ($question['prompt'] !== '')
                                                <span class="rg-admin-studio__persona-short">{{ $question['prompt'] }}</span>
                                            @endif
                                        </span>
                                        @if (! $question['open'])
                                            <x-admin.ui.badge tone="neutral">No longer open</x-admin.ui.badge>
                                        @else
                                            <x-admin.ui.badge :tone="$total > 0 ? 'outline' : 'neutral'">{{ $total > 0 ? $total.' '.($total === 1 ? 'answer' : 'answers') : 'No answers' }}</x-admin.ui.badge>
                                        @endif
                                    </div>
                                    @if (isset($ratings[$question['id']]))
                                        <div class="rg-admin-studio__votes-answers">
                                            <x-admin.ui.number-stepper
                                                label="Answers"
                                                id="rg-admin-votes-answers-{{ $question['id'] }}"
                                                :value="$total"
                                                :min="0"
                                                :max="$cap"
                                                :unit="'of '.$voters"
                                                x-on:change="$wire.setRatingTotal({{ $question['id'] }}, $event.target.value)"
                                            />
                                            <x-admin.ui.button size="sm" wire:click="evenSplit({{ $question['id'] }})" :disabled="! $editable || ! $question['open']">Even split</x-admin.ui.button>
                                        </div>
                                        <div class="rg-admin-studio__votes-options">
                                            @foreach ($question['options'] as $option)
                                                @php
                                                    $count = $rating['counts'][$option['id']] ?? 0;
                                                    $pct = $total > 0 ? (int) round($count / $total * 100) : 0;
                                                @endphp
                                                <div class="rg-admin-studio__votes-option" wire:key="votes-option-{{ $question['id'] }}-{{ $option['id'] }}">
                                                    <span class="rg-admin-studio__votes-option-label">
                                                        <span @class(['rg-admin-studio__votes-option-name', 'rg-admin-studio__votes-option-name--lead' => $count === $top && $total > 0])>
                                                            {{ $option['label'] }}
                                                            @unless ($option['open'])
                                                                <x-admin.ui.badge tone="warning">Not open</x-admin.ui.badge>
                                                            @endunless
                                                        </span>
                                                        <span class="rg-admin-studio__votes-bar" aria-hidden="true"><span @class(['rg-admin-studio__votes-bar-fill', 'rg-admin-studio__votes-bar-fill--lead' => $count === $top && $total > 0]) style="width: {{ $pct }}%"></span></span>
                                                    </span>
                                                    <span class="rg-admin-studio__muted rg-admin-studio__votes-pct">{{ $pct }}%</span>
                                                    <span class="rg-admin-studio__votes-move">
                                                        <x-admin.ui.icon-button icon="minus" :label="'Move an answer away from '.$option['label']" variant="ghost" size="sm" wire:click="moveAnswer({{ $question['id'] }}, {{ $option['id'] }}, 'down')" :disabled="! $editable || $count <= 0" />
                                                        <span class="rg-admin-studio__votes-count">{{ $count }}</span>
                                                        <x-admin.ui.icon-button icon="plus" :label="'Move an answer to '.$option['label']" variant="ghost" size="sm" wire:click="moveAnswer({{ $question['id'] }}, {{ $option['id'] }}, 'up')" :disabled="! $editable || ! $option['open'] || $total - $count <= 0" />
                                                    </span>
                                                </div>
                                            @endforeach
                                            <span class="rg-admin-hint">{{ $total > 0 ? '+ and − move one answer between options, from or to the most-answered other one. The total stays at '.$total.'; change it in Answers.' : 'Set the number of answers first.' }}</span>
                                            @if (collect($question['options'])->contains(fn (array $option): bool => ! $option['open'] && ($rating['counts'][$option['id']] ?? 0) > 0))
                                                <span class="rg-admin-hint rg-admin-studio__votes-warning-text">An option no longer open keeps the answers it had, and gets no new ones. Move them to an open option, or Even split.</span>
                                            @endif
                                        </div>
                                    @endif
                                    @error('ratings.'.$question['id'])
                                        <p class="rg-admin-error rg-admin-studio__votes-pad"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                                    @enderror
                                </div>
                            @endforeach
                        </div>
                        <p class="rg-admin-hint">Every active rating question is under the post on the site, so each is here: answers set to none plan none. When these votes and answers arrive is set on the Timing tab.</p>
                    </div>
                @endif
            </x-admin.ui.card>

            <div class="rg-admin-studio__generate">
                <span class="rg-admin-studio__generate-icon" aria-hidden="true"><x-admin.ui.icon name="thumbs-up" :size="16" /></span>
                <div class="rg-admin-studio__generate-text">
                    <div class="rg-admin-studio__generate-label">Prepared votes</div>
                    <div class="rg-admin-studio__generate-summary">
                        {{ $status }}
                        @if ($unsaved)
                            <span class="rg-admin-studio__node-unsaved">Not saved</span>
                        @endif
                    </div>
                    <div class="rg-admin-studio__generate-hint">Every change is saved at once. Prepared votes are not on the site: they are cast only once the plan is approved and runs.</div>
                    @foreach (['author', 'votes'] as $field)
                        @error($field)
                            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @enderror
                    @endforeach
                </div>
                <div class="rg-admin-studio__votes-actions">
                    @if ($unsaved)
                        <x-admin.ui.button wire:click="discard" :disabled="! $editable">Discard</x-admin.ui.button>
                    @elseif ($savedCount > 0)
                        <x-admin.ui.button variant="ghost" icon="trash-2" wire:click="askRemove" :disabled="! $editable">Remove post votes</x-admin.ui.button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($confirmingRemove)
        <x-admin.ui.confirm-dialog title="Remove the post votes?" tone="danger" icon="trash-2" id="rg-admin-votes-remove" x-on:dismiss="$wire.closeRemove()">
            Every prepared vote on the post and its rating questions leaves the plan. The discussion’s votes stay.
            <x-slot:details>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="thumbs-up" :size="16" />{{ $savedCount }} {{ $savedCount === 1 ? 'vote is' : 'votes are' }} removed</li>
                </ul>
            </x-slot:details>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="danger" icon="trash-2" x-on:click="hide(); $wire.confirmRemove()">Remove votes</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
