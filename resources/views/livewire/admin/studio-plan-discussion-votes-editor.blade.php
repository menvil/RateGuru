{{--
    The Discussion step's votes on its comments (StudioPlanDiscussionVotesEditor):
    the Comment voters, drawn up in the step's settings row beside the
    Discussion settings, and under the discussion the Comment votes card —
    each comment's up and down votes, set by hand or distributed by priority,
    the voters those make, and when they come after each comment. Under it the
    strip that builds a preview of the votes and saves exactly it, and the
    votes as saved, apart from the preview. Every text is drawn escaped.
--}}
@php
    $savedCount = count($savedRows);
    $previewCount = $previewRows !== null ? count($previewRows) : 0;
    $fresh = $previewRows !== null && ! $stale;
    $up = array_sum(array_column($comments, 'up'));
    $down = array_sum(array_column($comments, 'down'));
    $voted = count(array_filter($comments, fn (array $comment): bool => $comment['up'] + $comment['down'] > 0));
    $status = match (true) {
        $previewRows !== null && $stale => 'The settings or the comments changed since this preview. Rebuild it to save.',
        $previewRows !== null => $previewCount.' '.($previewCount === 1 ? 'vote' : 'votes').' in the preview, not saved yet. Save keeps exactly these.',
        $changed => 'The settings changed. Build a preview to see the votes before they are saved.',
        $savedCount > 0 => $savedCount.' '.($savedCount === 1 ? 'vote is' : 'votes are').' saved on the comments.',
        default => 'No comment votes prepared · the comments get only real users’ votes.',
    };
    $priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
@endphp

<div class="rg-admin-studio__votes rg-admin-studio__comment-votes">
    {{-- Comment voters (the prototype's VoterSelect, `which="comment"`), drawn in the step's settings row beside the Discussion settings: the discussion editor keeps the place for it, and this editor its state. --}}
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

    @if ($comments === [])
        <x-admin.ui.card title="Comment votes" description="Up and down votes on the prepared comments.">
            <x-admin.ui.empty-state icon="thumbs-up" title="No comments to vote on yet" :heading-level="3">
                Write or generate the discussion above first; its comments get their votes here.
            </x-admin.ui.empty-state>
        </x-admin.ui.card>
        @if ($savedCount > 0)
            <x-admin.ui.card title="Saved votes" :description="$savedCount.' '.($savedCount === 1 ? 'vote' : 'votes').' on comments · prepared, not cast'" flush>
                @include('livewire.admin.studio-plan-comment-votes-table', ['rows' => $savedRows, 'empty' => ''])
            </x-admin.ui.card>
        @endif
    @else
        <x-admin.ui.card title="Comment votes" description="Up and down votes on the prepared comments. Votes on the post and answers to its questions are set on step 2 · Post · Votes." flush>
            <div class="rg-admin-studio__votes-stats">
                <div class="rg-admin-studio__votes-stat">
                    <span class="rg-admin-overline">Up · down</span>
                    <span class="rg-admin-studio__votes-stat-value">+{{ $up }} / −{{ $down }}</span>
                    <span class="rg-admin-studio__votes-stat-note">{{ $up + $down }} comment {{ $up + $down === 1 ? 'vote' : 'votes' }}</span>
                </div>
                <div class="rg-admin-studio__votes-stat">
                    <span class="rg-admin-overline">Comments</span>
                    <span class="rg-admin-studio__votes-stat-value">{{ $voted }}</span>
                    <span class="rg-admin-studio__votes-stat-note">of {{ count($comments) }} get votes</span>
                </div>
                <div class="rg-admin-studio__votes-stat">
                    <span class="rg-admin-overline">Comment voters</span>
                    <span class="rg-admin-studio__votes-stat-value">{{ $voters }}</span>
                    <span class="rg-admin-studio__votes-stat-note">of {{ $cap }} personas who can vote</span>
                </div>
            </div>

            <x-admin.ui.tabs label="Comment votes" :active="$tab" class="rg-admin-studio__votes-tabs" :items="[
                ['id' => 'votes', 'label' => 'Comment votes', 'attributes' => ['wire:click' => '$set(\'tab\', \'votes\')']],
                ['id' => 'voters', 'label' => 'Voters', 'count' => $voterTable['voting'], 'attributes' => ['wire:click' => '$set(\'tab\', \'voters\')']],
                ['id' => 'timing', 'label' => 'Timing', 'attributes' => ['wire:click' => '$set(\'tab\', \'timing\')']],
            ]" />

            @if ($tab === 'voters')
                <div class="rg-admin-studio__votes-toolbar">
                    <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="votersSearch" autocomplete="off" class="rg-admin-studio__votes-search" />
                    <x-admin.ui.segmented label="Show" :options="['all' => 'All', 'voting' => 'Voting', 'writers' => 'Also commenting', 'idle' => 'Not used']" :value="$votersFilter" wire:model.live="votersFilter" />
                    <span class="rg-admin-studio__muted rg-admin-studio__votes-toolbar-count">{{ $voterTable['pool'] }} comment voters · {{ $voterTable['voting'] }} vote</span>
                    <x-admin.ui.button size="sm" icon="shuffle" :disabled="! $editable" wire:click="redraw">Reshuffle</x-admin.ui.button>
                </div>
                <p class="rg-admin-hint rg-admin-studio__votes-pad">{{ $previewRows !== null ? 'The preview, not saved yet.' : 'The votes as saved.' }} Each persona votes at most once per comment and never on a comment it writes. Reshuffle draws other voters and assignments with the same numbers.</p>
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
                                    <td title="{{ implode(', ', $row['up']) }}">@if ($row['up'] !== [])<span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--up">▲ {{ count($row['up']) }}</span>@else<span class="rg-admin-studio__muted">—</span>@endif</td>
                                    <td title="{{ implode(', ', $row['down']) }}">@if ($row['down'] !== [])<span class="rg-admin-studio__votes-way rg-admin-studio__votes-way--down">▼ {{ count($row['down']) }}</span>@else<span class="rg-admin-studio__muted">—</span>@endif</td>
                                    <td @class(['rg-admin-studio__muted' => $row['writes'] === []])>{{ $row['writes'] !== [] ? 'Writes '.implode(', ', $row['writes']) : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="rg-admin-studio__muted">No personas match.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @elseif ($tab === 'timing')
                <div class="rg-admin-studio__votes-body">
                    <p class="rg-admin-hint">Counted from the moment each comment appears: no vote can come before the comment it is on. Saved as a moment after the post.</p>
                    <div class="rg-admin-studio__votes-blocks">
                        <div class="rg-admin-studio__votes-block">
                            <div class="rg-admin-studio__votes-block-head">
                                <span class="rg-admin-studio__persona-name">Votes on each comment</span>
                                <span class="rg-admin-studio__muted">{{ count(array_filter($chips['first'], fn (array $chip): bool => $chip['on'])) === 0 || count(array_filter($chips['spread'], fn (array $chip): bool => $chip['on'])) === 0 ? 'The comments’ votes come at different moments: a choice here sets them all.' : 'The same for every comment.' }}</span>
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
                    <div class="rg-admin-studio__timing-scroll">
                        <table class="rg-admin-studio__timing rg-admin-studio__votes-table">
                            <thead>
                                <tr><th scope="col">ID</th><th scope="col">Comment appears</th><th scope="col">Votes</th><th scope="col">When they come</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($comments as $comment)
                                    <tr wire:key="comment-votes-timing-{{ $comment['id'] }}">
                                        <td class="rg-admin-studio__mono">{{ $comment['label'] }}</td>
                                        <td>{{ $comment['at'] }} after the post</td>
                                        <td>+{{ $comment['up'] }} / −{{ $comment['down'] }}</td>
                                        <td>
                                            {{ $comment['window'] }}
                                            @error('timing.'.$comment['id'])
                                                <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</span>
                                            @enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @else
                <div class="rg-admin-studio__comment-votes-distribute">
                    <x-admin.ui.number-stepper label="Total up" id="rg-admin-comment-votes-total-up" :value="$totalUp" :min="0" wire:model.live.debounce.400ms="totalUp" />
                    <x-admin.ui.number-stepper label="Total down" id="rg-admin-comment-votes-total-down" :value="$totalDown" :min="0" wire:model.live.debounce.400ms="totalDown" />
                    <x-admin.ui.button size="sm" icon="shuffle" wire:click="distribute" :disabled="! $editable">Distribute automatically</x-admin.ui.button>
                    <p class="rg-admin-hint">Priority decides each comment’s share of the totals: Low 1, Normal 2, High 3. Each count can be set by hand afterwards; nothing is saved until Save votes.</p>
                </div>
                <div class="rg-admin-studio__timing-scroll">
                    <table class="rg-admin-studio__timing rg-admin-studio__comment-votes-table">
                        <thead>
                            <tr><th scope="col">ID</th><th scope="col">Persona · comment</th><th scope="col">Appears</th><th scope="col">Priority</th><th scope="col">Up</th><th scope="col">Down</th><th scope="col">Score</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($comments as $comment)
                                <tr wire:key="comment-votes-row-{{ $comment['id'] }}" data-node="{{ $comment['label'] }}">
                                    <td class="rg-admin-studio__mono">{{ $comment['label'] }}</td>
                                    <td>
                                        <span @class(['rg-admin-studio__votes-who', 'rg-admin-studio__comment-votes-reply' => $comment['reply']])>
                                            @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $comment['persona']])
                                            <span class="rg-admin-studio__persona-text">
                                                <span class="rg-admin-studio__persona-name">{{ $comment['persona']['name'] ?? 'Deleted user' }}</span>
                                                <span class="rg-admin-studio__persona-short">{{ $comment['reply'] ? '↳ ' : '' }}{{ $comment['body'] }}</span>
                                            </span>
                                        </span>
                                        @if ($errors->has('targets.'.$comment['id']))
                                            <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $errors->first('targets.'.$comment['id']) }}</span>
                                        @elseif ($comment['short'] > 0)
                                            <span class="rg-admin-studio__votes-problem"><x-admin.ui.icon name="circle-alert" :size="12" /> Needs {{ $comment['up'] + $comment['down'] }} voters; {{ $comment['can'] }} can vote on it — {{ $comment['short'] }} short.</span>
                                        @endif
                                    </td>
                                    <td class="rg-admin-studio__votes-when">{{ $comment['at'] }}</td>
                                    <td>
                                        <span class="rg-admin-studio__votes-chips" role="radiogroup" aria-label="Priority of {{ $comment['label'] }}">
                                            @foreach ($priorities as $value => $label)
                                                <button type="button" role="radio" class="rg-admin-studio__votes-chip" aria-checked="{{ $comment['priority'] === $value ? 'true' : 'false' }}" aria-pressed="{{ $comment['priority'] === $value ? 'true' : 'false' }}" wire:click="setPriority({{ $comment['id'] }}, '{{ $value }}')" @disabled(! $editable)>{{ $label }}</button>
                                            @endforeach
                                        </span>
                                    </td>
                                    <td>@include('livewire.admin.studio-plan-vote-count', ['node' => $comment['id'], 'way' => 'up', 'count' => $comment['up'], 'name' => 'up votes on '.$comment['label'], 'editable' => $editable])</td>
                                    <td>@include('livewire.admin.studio-plan-vote-count', ['node' => $comment['id'], 'way' => 'down', 'count' => $comment['down'], 'name' => 'down votes on '.$comment['label'], 'editable' => $editable])</td>
                                    <td class="rg-admin-studio__votes-when">{{ ($comment['up'] - $comment['down']) >= 0 ? '+' : '' }}{{ $comment['up'] - $comment['down'] }}<span class="rg-admin-studio__muted"> · {{ $comment['up'] + $comment['down'] }} {{ $comment['up'] + $comment['down'] === 1 ? 'voter' : 'voters' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-admin.ui.card>

        <div class="rg-admin-studio__generate">
            <span class="rg-admin-studio__generate-icon" aria-hidden="true"><x-admin.ui.icon name="thumbs-up" :size="16" /></span>
            <div class="rg-admin-studio__generate-text">
                <div class="rg-admin-studio__generate-label">Prepared comment votes</div>
                <div class="rg-admin-studio__generate-summary">{{ $status }}</div>
                <div class="rg-admin-studio__generate-hint">Prepared votes are not on the site: they are cast only once the plan is approved and runs.</div>
                @foreach (['preview', 'votes', 'voterCount'] as $field)
                    @error($field)
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                    @enderror
                @endforeach
            </div>
            <div class="rg-admin-studio__votes-actions">
                @if ($changed || $previewRows !== null)
                    <x-admin.ui.button wire:click="discard" :disabled="! $editable">Discard</x-admin.ui.button>
                @endif
                @if ($savedCount > 0 && $previewRows === null && ! $changed)
                    <x-admin.ui.button variant="ghost" icon="trash-2" wire:click="askRemove" :disabled="! $editable">Remove comment votes</x-admin.ui.button>
                @endif
                <x-admin.ui.button icon="refresh-cw" wire:click="build" :disabled="! $editable">{{ $previewRows !== null ? 'Rebuild preview' : 'Build preview' }}</x-admin.ui.button>
                <x-admin.ui.button variant="primary" icon="check" wire:click="save" :disabled="! $editable || ! $fresh">Save votes</x-admin.ui.button>
            </div>
        </div>

        @if ($previewRows !== null)
            <x-admin.ui.card title="Preview" :description="'Not saved yet · '.$previewCount.' '.($previewCount === 1 ? 'vote' : 'votes').' on comments as Save votes keeps them'" flush>
                @include('livewire.admin.studio-plan-comment-votes-table', ['rows' => $previewRows, 'empty' => 'The settings ask for no votes: saving takes the comments’ votes away.'])
            </x-admin.ui.card>
        @endif

        <x-admin.ui.card title="Saved votes" :description="$savedCount.' '.($savedCount === 1 ? 'vote' : 'votes').' on comments · prepared, not cast'" flush>
            @include('livewire.admin.studio-plan-comment-votes-table', ['rows' => $savedRows, 'empty' => 'No votes are saved on the comments.'])
        </x-admin.ui.card>
    @endif

    @if ($confirmingRemove)
        <x-admin.ui.confirm-dialog title="Remove the comment votes?" tone="danger" icon="trash-2" id="rg-admin-comment-votes-remove" x-on:dismiss="$wire.closeRemove()">
            Every prepared vote on the comments and replies leaves the plan. The votes on the post and its questions stay.
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
