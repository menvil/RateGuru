{{--
    The Discussion step's votes on its comments (StudioPlanDiscussionVotesEditor),
    drawn where the step lays them out — the discussion editor keeps the places
    for them, this editor their state:
    - beside the Discussion settings, the Comment voters, and the Comment votes'
      totals and timing for every comment;
    - beside the discussion tree, the breakdown of the comment opened from it:
      its votes, its priority, when they come and who casts each, with Save
      votes and Discard;
    - on the tree's Voters tab, every voter with the comments it votes on, and
      on its Timing tab every comment, reply and vote in the order they come,
      each moment to change: a vote's here, a comment's said to the discussion
      editor, which saves it at once and moves its votes with it.
    The tree and the Generate strip read the votes as set here from the
    `rgCommentVotes` store, and the step's Back, Continue and steps ask before
    leaving votes unsaved, listing what changed. Here, under the tree, only
    what keeps the votes from being saved is said. Every text is drawn escaped.
--}}
@php
    $priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
    $shownChanges = array_slice($changes, 0, 8);
@endphp

<div
    class="rg-admin-studio__votes rg-admin-studio__comment-votes"
    x-data
    x-effect="Object.assign($store.rgCommentVotes ?? {}, { nodes: $wire.summary, selected: $wire.selected, unsaved: $wire.unsaved, status: $wire.status })"
    x-on:beforeunload.window="if ($wire.unsaved && ! $store.adminEditor?.leaving) { $event.preventDefault(); $event.returnValue = '' }"
>
    {{-- Comment voters (the prototype's VoterSelect, `which="comment"`), drawn in the step's settings row beside the Discussion settings. --}}
    @teleport('#rg-admin-studio-comment-voters')
        <div class="rg-admin-studio__comment-voters">
            <x-admin.ui.card title="Comment voters" description="Personas who vote on comments. Separate from the commenters; the same persona can be in both, never on its own comment.">
                <div class="rg-admin-studio__discussion-fields">
                    <x-admin.ui.segmented label="How comment voters are chosen" :options="['random' => 'Random', 'manual' => 'Choose']" :value="$voterMode" wire:model.live="voterMode" class="rg-admin-studio__discussion-mode" />

                    @if ($voterMode === 'random')
                        <div class="rg-admin-studio__votes-random">
                            <x-admin.ui.number-stepper
                                label="Number of personas"
                                id="rg-admin-comment-votes-voter-count"
                                :value="$voterCount"
                                :min="0"
                                :max="$cap"
                                :unit="'of '.$cap"
                                :error="$errors->first('voterCount') ?: null"
                                wire:model.live.debounce.400ms="voterCount"
                            />
                            <x-admin.ui.button size="sm" icon="shuffle" :disabled="! $editable" wire:click="redraw">Pick others</x-admin.ui.button>
                        </div>
                        <p class="rg-admin-hint">Random from the active personas. Pick others keeps the number and draws different ones.</p>
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
                                    <span class="rg-admin-studio__chip rg-admin-studio__chip--removable" wire:key="comment-votes-chosen-{{ $persona['id'] }}">
                                        {{ $persona['username'] ?? $persona['name'] }}
                                        @if (($persona['problem'] ?? null) === 'deleted')
                                            <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
                                        @elseif (($persona['problem'] ?? null) === 'not_persona')
                                            <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
                                        @elseif (($persona['problem'] ?? null) === 'restricted' && ($persona['status'] ?? null) !== null)
                                            <x-admin.ui.badge :tone="$persona['status']['tone']">{{ $persona['status']['label'] }}</x-admin.ui.badge>
                                        @endif
                                        <button type="button" class="rg-admin-studio__chip-remove" aria-label="Remove {{ $persona['name'] }}" wire:click="toggleVoter({{ $persona['id'] }})" @disabled(! $editable)>
                                            <x-admin.ui.icon name="x" :size="11" />
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                            @if (collect($chosen)->contains(fn (array $persona): bool => ($persona['problem'] ?? null) !== null))
                                <p class="rg-admin-hint rg-admin-studio__votes-warning-text">A deleted or restricted persona gets no new votes. A restricted one keeps a vote it has until you replace it.</p>
                            @endif
                        @endif
                        <div class="rg-admin-studio__persona-list" role="group" aria-label="Personas to choose">
                            @forelse ($listed as $persona)
                                <button type="button" role="checkbox" aria-checked="{{ $persona['on'] ? 'true' : 'false' }}" class="rg-admin-studio__persona-option" wire:key="comment-votes-persona-{{ $persona['id'] }}" wire:click="toggleVoter({{ $persona['id'] }})" @disabled(! $editable)>
                                    <span class="rg-admin-studio__check" aria-hidden="true">
                                        @if ($persona['on'])
                                            <x-admin.ui.icon name="check" :size="12" />
                                        @endif
                                    </span>
                                    @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $persona])
                                    <span class="rg-admin-studio__persona-text">
                                        <span class="rg-admin-studio__persona-name">{{ $persona['name'] }} <span class="rg-admin-studio__muted">{{ $persona['username'] ?? '' }}</span></span>
                                        <span class="rg-admin-studio__persona-short">{{ $persona['writes'] !== [] ? 'Writes '.implode(', ', $persona['writes']).' · can’t vote on '.(count($persona['writes']) === 1 ? 'it' : 'those') : $persona['short'] }}</span>
                                    </span>
                                </button>
                            @empty
                                <p class="rg-admin-hint rg-admin-studio__persona-none">{{ trim($voterSearch) !== '' ? 'No personas match “'.$voterSearch.'”.' : 'There are no active personas.' }}</p>
                            @endforelse
                        </div>
                    @endif

                    <p class="rg-admin-hint">{{ $voters }} {{ $voters === 1 ? 'persona' : 'personas' }} can vote on comments. A comment can get at most {{ max(0, $voters - 1) }}–{{ $voters }} votes: its own author can’t vote on it.</p>
                </div>
            </x-admin.ui.card>
        </div>
    @endteleport

    {{-- Comment votes: the totals shared out over the comments by priority, and when every comment's votes come — the third card of the step's settings row. --}}
    @teleport('#rg-admin-studio-comment-vote-settings')
        <div class="rg-admin-studio__comment-vote-settings">
            <x-admin.ui.card title="Comment votes" description="Up and down votes on the prepared comments, cast only once the plan is approved and runs. Each comment’s own are set in its breakdown: See breakdown on the comment.">
                @if ($commentCount === 0)
                    <x-admin.ui.empty-state icon="thumbs-up" title="No comments to vote on yet" :heading-level="3">
                        Write or generate the discussion first; its comments get their votes here.
                    </x-admin.ui.empty-state>
                @else
                    <div class="rg-admin-studio__discussion-fields">
                        <div class="rg-admin-studio__comment-votes-distribute">
                            <x-admin.ui.number-stepper label="Total up" id="rg-admin-comment-votes-total-up" :value="$totalUp" :min="0" wire:model.live.debounce.400ms="totalUp" />
                            <x-admin.ui.number-stepper label="Total down" id="rg-admin-comment-votes-total-down" :value="$totalDown" :min="0" wire:model.live.debounce.400ms="totalDown" />
                            <x-admin.ui.button size="sm" icon="shuffle" wire:click="distribute" :disabled="! $editable">Distribute automatically</x-admin.ui.button>
                            <p class="rg-admin-hint">On {{ $voted }} of {{ $commentCount }} {{ $commentCount === 1 ? 'comment' : 'comments' }}. A total changed gives or takes the difference by each comment’s priority — Low 1, Normal 2, High 3 — keeping the rest, and a comment’s own votes add up to it. Distribute shares both totals out anew.</p>
                            @error('votes')
                                <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                            @enderror
                        </div>
                        <div class="rg-admin-studio__votes-block">
                            <div class="rg-admin-studio__votes-block-head">
                                <span class="rg-admin-studio__persona-name">When the votes come</span>
                                <span class="rg-admin-studio__muted">{{ count(array_filter($chips['first'], fn (array $chip): bool => $chip['on'])) === 0 || count(array_filter($chips['spread'], fn (array $chip): bool => $chip['on'])) === 0 ? 'The comments’ votes come at different moments: a choice here sets them all.' : 'From each comment’s own moment, the same for every comment.' }}</span>
                            </div>
                            @foreach (['first' => 'First vote after', 'spread' => 'Spread over'] as $field => $label)
                                <div class="rg-admin-studio__votes-chips" role="group" aria-label="{{ $label }}">
                                    <span class="rg-admin-studio__votes-chips-label">{{ $label }}</span>
                                    @foreach ($chips[$field] as $chip)
                                        <button type="button" class="rg-admin-studio__votes-chip" aria-pressed="{{ $chip['on'] ? 'true' : 'false' }}" wire:click="setTiming('{{ $field }}', {{ $chip['seconds'] }})" @disabled(! $editable)>{{ $chip['label'] }}</button>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-admin.ui.card>
        </div>
    @endteleport

    {{-- The breakdown of the comment opened with See breakdown, beside the discussion tree. --}}
    @teleport('#rg-admin-studio-comment-breakdown')
        <div class="rg-admin-studio__breakdown-host">
            @if ($breakdown !== null)
                {{-- Opened above the screen, where it takes the tree's place: brought into view. --}}
                <x-admin.ui.card :title="'Comment votes · '.$breakdown['label']" class="rg-admin-studio__breakdown" flush wire:key="comment-votes-breakdown-{{ $breakdown['id'] }}" x-init="if ($el.getBoundingClientRect().top < 0) $el.scrollIntoView({ block: 'start' })">
                    <x-slot:aside>
                        <x-admin.ui.icon-button icon="x" label="Close the breakdown" variant="ghost" size="sm" wire:click="closeBreakdown" />
                    </x-slot:aside>

                    <div class="rg-admin-studio__breakdown-body">
                        <div class="rg-admin-studio__breakdown-comment">
                            @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $breakdown['persona']])
                            <div class="rg-admin-studio__persona-text">
                                <span class="rg-admin-studio__persona-name">{{ $breakdown['persona']['name'] ?? 'Deleted user' }} <span class="rg-admin-studio__muted">{{ $breakdown['replyTo'] !== null ? '↳ '.$breakdown['replyTo'] : '' }}</span></span>
                                <span class="rg-admin-studio__breakdown-text">{{ $breakdown['body'] }}</span>
                                <span class="rg-admin-studio__persona-short">Appears {{ $breakdown['at'] }} after the post</span>
                            </div>
                        </div>

                        <div class="rg-admin-studio__breakdown-counts">
                            <div class="rg-admin-field">
                                <span class="rg-admin-label">Up</span>
                                @include('livewire.admin.studio-plan-vote-count', ['node' => $breakdown['id'], 'way' => 'up', 'count' => $breakdown['up'], 'name' => 'up votes on '.$breakdown['label'], 'editable' => $editable])
                            </div>
                            <div class="rg-admin-field">
                                <span class="rg-admin-label">Down</span>
                                @include('livewire.admin.studio-plan-vote-count', ['node' => $breakdown['id'], 'way' => 'down', 'count' => $breakdown['down'], 'name' => 'down votes on '.$breakdown['label'], 'editable' => $editable])
                            </div>
                            <div class="rg-admin-field">
                                <span class="rg-admin-label">Score</span>
                                <span class="rg-admin-studio__votes-when">{{ ($breakdown['up'] - $breakdown['down']) >= 0 ? '+' : '' }}{{ $breakdown['up'] - $breakdown['down'] }}<span class="rg-admin-studio__muted"> · {{ $breakdown['up'] + $breakdown['down'] }} {{ $breakdown['up'] + $breakdown['down'] === 1 ? 'voter' : 'voters' }}</span></span>
                            </div>
                        </div>

                        @if ($breakdown['problem'] !== null)
                            <p class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $breakdown['problem'] }}</p>
                        @endif

                        <div class="rg-admin-studio__votes-chips" role="radiogroup" aria-label="Priority of {{ $breakdown['label'] }}">
                            <span class="rg-admin-studio__votes-chips-label">Priority</span>
                            @foreach ($priorities as $value => $label)
                                <button type="button" role="radio" class="rg-admin-studio__votes-chip" aria-checked="{{ $breakdown['priority'] === $value ? 'true' : 'false' }}" aria-pressed="{{ $breakdown['priority'] === $value ? 'true' : 'false' }}" wire:click="setPriority({{ $breakdown['id'] }}, '{{ $value }}')" @disabled(! $editable)>{{ $label }}</button>
                            @endforeach
                        </div>
                        <p class="rg-admin-hint">How much of Total up and Total down this comment gets when Distribute automatically shares them: High three parts, Normal two, Low one. It changes no vote until then.</p>

                        @foreach (['first' => 'First vote after', 'spread' => 'Spread over'] as $field => $label)
                            <div class="rg-admin-studio__votes-chips" role="group" aria-label="{{ $label }} {{ $breakdown['label'] }}">
                                <span class="rg-admin-studio__votes-chips-label">{{ $label }}</span>
                                @foreach ($breakdown['chips'][$field] as $chip)
                                    <button type="button" class="rg-admin-studio__votes-chip" aria-pressed="{{ $chip['on'] ? 'true' : 'false' }}" wire:click="setTiming('{{ $field }}', {{ $chip['seconds'] }}, {{ $breakdown['id'] }})" @disabled(! $editable)>{{ $chip['label'] }}</button>
                                @endforeach
                            </div>
                        @endforeach
                        <p class="rg-admin-hint">{{ $breakdown['window'] }}</p>
                    </div>

                    <div class="rg-admin-studio__breakdown-head">
                        <span class="rg-admin-overline">Who votes</span>
                        @if ($breakdown['changed'])
                            <x-admin.ui.badge tone="warning">Not saved</x-admin.ui.badge>
                        @endif
                    </div>
                    <ul class="rg-admin-studio__breakdown-votes" aria-label="Votes on {{ $breakdown['label'] }}">
                        @forelse ($breakdown['votes'] as $vote)
                            <li class="rg-admin-studio__breakdown-vote" wire:key="comment-votes-breakdown-vote-{{ $vote['persona']['id'] ?? $loop->index }}">
                                @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $vote['persona']])
                                <span class="rg-admin-studio__persona-text">
                                    <span class="rg-admin-studio__persona-name">
                                        {{ $vote['persona']['name'] ?? 'Deleted user' }}
                                        @if (($vote['persona']['problem'] ?? null) === 'deleted')
                                            <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
                                        @elseif (($vote['persona']['problem'] ?? null) === 'not_persona')
                                            <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
                                        @elseif (($vote['persona']['problem'] ?? null) === 'restricted' && ($vote['persona']['status'] ?? null) !== null)
                                            <x-admin.ui.badge :tone="$vote['persona']['status']['tone']">{{ $vote['persona']['status']['label'] }}</x-admin.ui.badge>
                                        @endif
                                    </span>
                                    <span class="rg-admin-studio__persona-short">{{ $vote['when'] }} after the post · {{ $vote['after'] }}</span>
                                    @if ($vote['problem'] !== null)
                                        <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $vote['problem'] }}</span>
                                    @endif
                                </span>
                                @include('livewire.admin.studio-plan-post-vote-cell', ['vote' => $vote['vote']])
                            </li>
                        @empty
                            <li class="rg-admin-studio__breakdown-none rg-admin-studio__muted">{{ $breakdown['waiting'] ? 'Fix what is marked above to see who votes.' : 'No votes on this comment.' }}</li>
                        @endforelse
                    </ul>

                    <div class="rg-admin-studio__breakdown-footer">
                        <span class="rg-admin-studio__muted">{{ $unsaved ? 'Not saved yet · Save votes keeps every comment’s votes.' : 'As saved.' }}</span>
                        @if ($unsaved)
                            <x-admin.ui.button size="sm" wire:click="discard" :disabled="! $editable">Discard</x-admin.ui.button>
                        @endif
                        <x-admin.ui.button size="sm" variant="primary" icon="check" wire:click="save" :disabled="! $editable || ! $unsaved || $marked !== []">Save votes</x-admin.ui.button>
                    </div>
                </x-admin.ui.card>
            @endif
        </div>
    @endteleport

    {{-- The tree's Voters tab: every voter with the comments it votes on and writes. --}}
    @teleport('#rg-admin-studio-comment-voters-table')
        <div class="rg-admin-studio__comment-voters-table">
            <div class="rg-admin-studio__votes-toolbar">
                <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="votersSearch" autocomplete="off" class="rg-admin-studio__votes-search" />
                <x-admin.ui.segmented label="Show" :options="['all' => 'All', 'voting' => 'Voting', 'writers' => 'Also commenting', 'idle' => 'Not used']" :value="$votersFilter" wire:model.live="votersFilter" />
                <span class="rg-admin-studio__muted rg-admin-studio__votes-toolbar-count">{{ $voterTable['pool'] }} comment voters · {{ $voterTable['voting'] }} vote</span>
                <x-admin.ui.button size="sm" icon="shuffle" :disabled="! $editable" wire:click="redraw">Reshuffle</x-admin.ui.button>
            </div>
            <p class="rg-admin-hint rg-admin-studio__votes-pad">{{ $unsaved ? 'The votes as set here, not saved yet.' : 'The votes as saved.' }} Each persona votes at most once per comment and never on a comment it writes. Reshuffle draws other voters and assignments with the same numbers.</p>
            <div class="rg-admin-studio__timing-scroll">
                <table class="rg-admin-studio__timing rg-admin-studio__votes-table">
                    <thead>
                        <tr><th scope="col">Persona</th><th scope="col">Votes up</th><th scope="col">Votes down</th><th scope="col">Comments</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($voterTable['rows'] as $row)
                            <tr @class(['rg-admin-studio__votes-idle' => ! $row['voting']]) wire:key="comment-votes-voter-{{ $row['persona']['id'] ?? $loop->index }}">
                                <td>
                                    <span class="rg-admin-studio__votes-who">
                                        @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $row['persona']])
                                        <span class="rg-admin-studio__persona-text">
                                            <span class="rg-admin-studio__persona-name">{{ $row['persona']['name'] ?? 'Deleted user' }}</span>
                                            <span class="rg-admin-studio__persona-short">{{ isset($row['persona']['username']) ? '@'.$row['persona']['username'] : '' }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td title="{{ implode(', ', $row['up']) }}">@if ($row['up'] !== [])<span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--up">▲ {{ count($row['up']) }}</span> <span class="rg-admin-studio__muted">{{ implode(', ', $row['up']) }}</span>@else<span class="rg-admin-studio__muted">—</span>@endif</td>
                                <td title="{{ implode(', ', $row['down']) }}">@if ($row['down'] !== [])<span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--down">▼ {{ count($row['down']) }}</span> <span class="rg-admin-studio__muted">{{ implode(', ', $row['down']) }}</span>@else<span class="rg-admin-studio__muted">—</span>@endif</td>
                                <td @class(['rg-admin-studio__muted' => $row['writes'] === []])>{{ $row['writes'] !== [] ? 'Writes '.implode(', ', $row['writes']) : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="rg-admin-studio__muted">No personas match.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endteleport

    {{-- The tree's Timing tab: every comment, reply and vote on them in the order they come, each with its moment to change. --}}
    @teleport('#rg-admin-studio-timing')
        <div class="rg-admin-studio__timing-all">
            <p class="rg-admin-hint rg-admin-studio__timing-note">Times are offsets from the moment the post appears; the date is set on the next step. A comment moved is saved at once and takes its votes with it. A vote never comes before its comment, nor a reply before the comment it answers.</p>
            <div class="rg-admin-studio__timing-scroll">
                <table class="rg-admin-studio__timing">
                    <thead>
                        <tr><th scope="col">#</th><th scope="col">Action</th><th scope="col">Executor</th><th scope="col">Parent</th><th scope="col">Offset from post</th><th scope="col">After its parent</th><th scope="col">Dependency</th><th scope="col">Votes</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($timing as $index => $row)
                            <tr @class(['rg-admin-studio__timing-vote' => $row['kind'] === 'vote']) wire:key="timing-{{ $row['key'] }}-{{ $row['seconds'] }}" data-timing="{{ $row['key'] }}">
                                <td class="rg-admin-studio__timing-n">{{ $index + 1 }}</td>
                                <td>
                                    <span class="rg-admin-studio__timing-action">
                                        <x-admin.ui.icon :name="match ($row['kind']) { 'reply' => 'reply', 'vote' => $row['vote'] === 'up' ? 'thumbs-up' : 'thumbs-down', default => 'message-square' }" :size="14" />{{ $row['action'] }}
                                        @if ($row['changed'])
                                            <span class="rg-admin-studio__node-unsaved">Not saved</span>
                                        @endif
                                        @if ($row['problem'] !== null)
                                            <span class="rg-admin-studio__node-problem" title="{{ $row['problem'] }}"><x-admin.ui.icon name="circle-alert" :size="13" :label="$row['problem']" /></span>
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    <span class="rg-admin-studio__votes-who">
                                        @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $row['who']])
                                        <span class="rg-admin-studio__persona-name">{{ $row['who']['name'] ?? 'Deleted user' }}</span>
                                    </span>
                                </td>
                                <td class="rg-admin-studio__mono">{{ $row['parent'] }}</td>
                                <td>
                                    {{-- A comment's moment is the discussion editor's to save: the field keeps the moment it has, and a move accepted draws the row again with the new one. A vote's is set here, saved with Save votes. No comment moves while the discussion editor says a generation is on its way. --}}
                                    @if ($row['kind'] === 'vote')
                                        @include('livewire.admin.studio-plan-offset-field', [
                                            'seconds' => $row['seconds'],
                                            'label' => 'Offset of '.$row['action'].' by '.($row['who']['name'] ?? 'a deleted user').' on '.$row['parent'],
                                            'call' => '$wire.setMoment('.$row['node'].', '.$row['persona'].', seconds)',
                                            'disabled' => ! $editable,
                                        ])
                                    @else
                                        @include('livewire.admin.studio-plan-offset-field', [
                                            'seconds' => $row['seconds'],
                                            'label' => 'Offset of '.$row['action'],
                                            'call' => '$dispatch('.\Illuminate\Support\Js::from(\App\Livewire\Admin\StudioPlanDiscussionEditor::OFFSET_EVENT).', { id: '.$row['node'].', seconds })',
                                            'disabled' => ! $moving,
                                            'disabledWhile' => '$store.rgCommentVotes?.generating',
                                        ])
                                    @endif
                                </td>
                                <td @class(['rg-admin-studio__muted' => $row['after'] === null])>{{ $row['after'] ?? '—' }}</td>
                                <td><span class="rg-admin-studio__timing-dependency"><x-admin.ui.icon name="circle-check" :size="13" />{{ $row['dependency'] }}</span></td>
                                <td>
                                    @if ($row['votes'] === [0, 0])
                                        <span class="rg-admin-studio__muted">—</span>
                                    @elseif ($row['votes'] !== null)
                                        <span class="rg-admin-studio__votes-when">+{{ $row['votes'][0] }} / −{{ $row['votes'][1] }}</span>
                                        <span class="rg-admin-studio__timing-window rg-admin-studio__muted">{{ $row['window'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($unsaved)
                <div class="rg-admin-studio__breakdown-footer rg-admin-studio__timing-footer">
                    <span class="rg-admin-studio__muted">The votes changed here are not saved yet · Save votes keeps every comment’s votes.</span>
                    <x-admin.ui.button size="sm" wire:click="discard" :disabled="! $editable">Discard</x-admin.ui.button>
                    <x-admin.ui.button size="sm" variant="primary" icon="check" wire:click="save" :disabled="! $editable || $marked !== []">Save votes</x-admin.ui.button>
                </div>
            @endif
        </div>
    @endteleport

    @if ($gone)
        <x-admin.ui.inline-notice tone="warning">This draft was deleted, so its comment votes can’t be changed any more.</x-admin.ui.inline-notice>
    @elseif ($closed)
        <x-admin.ui.inline-notice tone="info">This plan is no longer a draft, so its comment votes can’t be changed here.</x-admin.ui.inline-notice>
    @elseif ($conflict)
        <x-admin.ui.inline-notice tone="warning">
            Someone changed this draft after you opened it, so no comment votes are saved until you reload it.
            <x-slot:actions>
                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload votes</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.inline-notice>
    @endif

    @if ($leaving !== null)
        <x-admin.ui.confirm-dialog title="Save the comment votes?" tone="warning" icon="thumbs-up" id="rg-admin-comment-votes-leave" x-on:dismiss="$wire.stay()">
            {{ $marked !== [] ? 'The comment votes changed here are not saved yet, and can’t be until what is marked is fixed. Keep editing to fix it, or discard the changes.' : 'The comment votes changed here are not saved yet. Save them before you go on, or discard the changes.' }}
            <x-slot:details>
                <ul class="rg-admin-dialog-facts" aria-label="Not saved">
                    @forelse ($shownChanges as $change)
                        <li class="rg-admin-dialog-facts__item" wire:key="comment-votes-change-{{ $change['label'] }}"><x-admin.ui.icon :name="$change['problem'] ? 'circle-alert' : 'thumbs-up'" :size="16" /><span><span class="rg-admin-studio__mono">{{ $change['label'] }}</span> · {{ $change['text'] }}</span></li>
                    @empty
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="thumbs-up" :size="16" />{{ ucfirst($status) }}</li>
                    @endforelse
                    @if (count($changes) > count($shownChanges))
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="ellipsis" :size="16" />{{ count($changes) - count($shownChanges) }} more {{ count($changes) - count($shownChanges) === 1 ? 'comment' : 'comments' }}</li>
                    @endif
                </ul>
            </x-slot:details>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()">Keep editing</x-admin.ui.button>
                <x-admin.ui.button x-on:click="hide(); $wire.discardAndLeave()">Discard changes</x-admin.ui.button>
                <x-admin.ui.button variant="primary" icon="check" :disabled="$marked !== []" x-on:click="hide(); $wire.saveAndLeave()">Save and continue</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
