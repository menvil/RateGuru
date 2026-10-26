{{--
    The Discussion step (StudioPlanDiscussionEditor), laid out and working as
    the prototype's: Discussion settings — the counts, the Personas and the
    tone — with the Comment voters and the Comment votes beside them (drawn by
    StudioPlanDiscussionVotesEditor);
    Generate tree in a strip of its own under them, saying the comment votes
    too; then the discussion tree — Comments, each with its moment, its up and
    down votes as set on the step, Reply, Edit, Regenerate, Delete and See
    breakdown, which opens the comment's votes beside the tree; Voters; and
    Timing, every comment and vote in one list, drawn by the votes editor. A comment is edited
    in a dialog, and everything is written to the plan at once. While a
    generation is on its way the step is read again every few seconds: a
    whole tree is drawn as placeholders, a comment written again is faded.
    While the photo's analysis is, the Generate strip is read again too, so
    Generate tree comes on by itself once the analysis is complete.
    The places the votes editor draws in are kept empty here (wire:ignore),
    and the votes as set there are read from the `rgCommentVotes` store.
    Every text is drawn escaped.
--}}
@php
    $units = \App\Livewire\Admin\StudioPlanDiscussionEditor::UNITS;
    $notReady = match (true) {
        ! $configured => 'Discussion generation is not configured. Comments can still be written by hand.',
        ! $hasTitle => 'Save the post’s final title first: the discussion is written from it.',
        ! $analysed => 'The discussion can be written once the photo’s analysis is complete.',
        default => null,
    };
    $hasTree = $rows !== [];
    $noTeam = $totalCount > 0 && $participantMode === 'manual' && $chosen === [];
    // Only the title not saved yet keeps it: Generate tree saves the post first, so changes to save let it go on.
    $titleOnly = $totalCount > 0 && $configured && ! $hasTitle && $analysed;
    $generateBlocked = ! $editable || $underway || $noTeam || ($totalCount > 0 && $notReady !== null && ! $titleOnly);
    $rewriteBlocked = ! $editable || $underway || $notReady !== null;
    $summary = $totalCount === 0
        ? 'No discussion · the post publishes without automated comments'
        : $totalCount.' '.($totalCount === 1 ? 'comment' : 'comments').' · '.$rootCount.' top-level · '.$personaCount.' '.($personaCount === 1 ? 'persona' : 'personas').' · '.$chosenTone->label();
    // The comment votes as saved, until the votes editor says them as set on the step.
    $votesSaved = \App\Livewire\Admin\StudioPlanDiscussionVotesEditor::tallied(array_sum(array_column($rows, 'up')), array_sum(array_column($rows, 'down')));
    [$hint, $hintTone] = match (true) {
        $noTeam => ['Select at least one persona.', 'danger'],
        $totalCount > 0 && $notReady !== null => [$notReady, 'muted'],
        $underway => ['A generation is on its way. The tree is replaced as soon as it is ready.', 'muted'],
        $totalCount === 0 => ['Nothing is generated: the tree is emptied.', 'muted'],
        $hasTree => ['The tree has comments. You’ll be asked before it’s replaced.', 'muted'],
        default => ['One request to OpenAI — a paid call.', 'muted'],
    };
@endphp

<div
    class="rg-admin-studio__discussion"
    data-generation="{{ $busy ? 'tree' : ($underway ? 'comment' : 'none') }}"
    {{-- Said to the votes editor's Timing tab: no comment moves while a generation is on its way. --}}
    x-data
    x-effect="$store.rgCommentVotes && ($store.rgCommentVotes.generating = $wire.awaiting !== null)"
    @if ($underway) wire:poll.{{ $pollSeconds }}s.visible="check" @endif
>
    @if ($gone)
        <x-admin.ui.inline-notice tone="warning">This draft was deleted, so its discussion can’t be changed any more.</x-admin.ui.inline-notice>
    @elseif ($closed)
        <x-admin.ui.inline-notice tone="info">This plan is no longer a draft, so its discussion can’t be changed here.</x-admin.ui.inline-notice>
    @elseif ($conflict)
        <x-admin.ui.inline-notice tone="warning">
            Someone changed this draft after you opened it, so nothing here is saved until you reload it.
            <x-slot:actions>
                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload discussion</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.inline-notice>
    @endif

    <div class="rg-admin-studio__discussion-cards">
        <x-admin.ui.card title="Discussion settings" description="Comments are generated in English from persona descriptions.">
            <div class="rg-admin-studio__discussion-fields">
                <x-admin.ui.number-stepper
                    label="Total comments"
                    id="rg-admin-discussion-total"
                    :value="$totalCount"
                    :min="0"
                    :max="$mostNodes"
                    :range="'0–'.$mostNodes"
                    unit="comments"
                    :hint="$totalCount === 0 ? 'No discussion: the post publishes without automated comments.' : null"
                    wire:model.live.debounce.400ms="totalCount"
                />
                <x-admin.ui.number-stepper
                    label="Top-level comments"
                    id="rg-admin-discussion-roots"
                    :value="$rootCount"
                    :min="$totalCount > 0 ? 1 : 0"
                    :max="$totalCount"
                    :range="($totalCount > 0 ? 1 : 0).'–'.$totalCount"
                    :unit="'of '.$totalCount"
                    hint="First-level comments. The rest are replies."
                    wire:model.live.debounce.400ms="rootCount"
                />
                @if ($participantMode === 'random')
                    <x-admin.ui.number-stepper
                        label="Number of personas"
                        id="rg-admin-discussion-random"
                        :value="$randomCount"
                        :min="1"
                        :max="$mostRandom"
                        :range="'1–'.$mostRandom"
                        :unit="'of '.$available.' active'"
                        wire:model.live.debounce.400ms="randomCount"
                    />
                @endif

                <div class="rg-admin-field rg-admin-studio__discussion-team">
                    <span class="rg-admin-label">Personas</span>
                    <x-admin.ui.segmented label="Personas" :options="['random' => 'Random', 'manual' => 'Manual']" :value="$participantMode" wire:model.live="participantMode" class="rg-admin-studio__discussion-mode" />
                    <p class="rg-admin-hint">{{ $participantMode === 'random' ? 'Picks the number of personas at random from the active ones.' : 'Only the checked personas take part.' }}</p>

                    @if ($participantMode === 'random')
                        <div class="rg-admin-studio__discussion-picked">
                            <span class="rg-admin-hint">{{ $pickedNames !== [] ? 'Picked for the current tree. Generate again to reshuffle.' : 'Personas are picked when the tree is generated.' }}</span>
                            @if ($pickedNames !== [])
                                <div class="rg-admin-studio__chips">
                                    @foreach ($pickedNames as $name)
                                        <span class="rg-admin-studio__chip">{{ $name }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="participantSearch" autocomplete="off" />
                        <div class="rg-admin-studio__discussion-count">
                            <span>{{ count($chosen) }} of {{ $available }} active personas selected · up to {{ $mostPersonas }} take part</span>
                            <button type="button" class="rg-admin-studio__text-button" wire:click="selectShown">{{ $selectLabel }}</button>
                            <button type="button" class="rg-admin-studio__text-button" wire:click="clearParticipants">Clear</button>
                        </div>
                        @if ($chosen !== [])
                            <div class="rg-admin-studio__chips">
                                @foreach ($chosen as $persona)
                                    <span class="rg-admin-studio__chip rg-admin-studio__chip--removable" wire:key="discussion-chosen-{{ $persona['id'] }}">
                                        {{ $persona['username'] ?? $persona['name'] }}
                                        <button type="button" class="rg-admin-studio__chip-remove" aria-label="Remove {{ $persona['name'] }}" wire:click="toggleParticipant({{ $persona['id'] }})">
                                            <x-admin.ui.icon name="x" :size="11" />
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        <div class="rg-admin-studio__persona-list" role="group" aria-label="Personas to check">
                            @forelse ($listed as $persona)
                                <button type="button" role="checkbox" aria-checked="{{ $persona['on'] ? 'true' : 'false' }}" class="rg-admin-studio__persona-option" wire:key="discussion-persona-{{ $persona['id'] }}" wire:click="toggleParticipant({{ $persona['id'] }})">
                                    <span class="rg-admin-studio__check" aria-hidden="true">
                                        @if ($persona['on'])
                                            <x-admin.ui.icon name="check" :size="12" />
                                        @endif
                                    </span>
                                    @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $persona])
                                    <span class="rg-admin-studio__persona-text">
                                        <span class="rg-admin-studio__persona-name">{{ $persona['name'] }} <span class="rg-admin-studio__muted">{{ $persona['username'] ?? '' }}</span></span>
                                        @if ($persona['short'] !== '')
                                            <span class="rg-admin-studio__persona-short">{{ $persona['short'] }}</span>
                                        @endif
                                    </span>
                                </button>
                            @empty
                                <p class="rg-admin-hint rg-admin-studio__persona-none">{{ $searching ? 'No active personas match “'.$participantSearch.'”.' : 'There are no active personas.' }}</p>
                            @endforelse
                        </div>
                    @endif

                    @error('participants')
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                    @enderror
                </div>

                <div class="rg-admin-field rg-admin-studio__discussion-team">
                    <span class="rg-admin-label" id="rg-admin-discussion-tone-label">Tone</span>
                    <div class="rg-admin-studio__tones" role="group" aria-labelledby="rg-admin-discussion-tone-label">
                        @foreach ($tones as $option)
                            <button
                                type="button"
                                class="rg-admin-studio__tone"
                                aria-pressed="{{ $chosenTone->value === $option['value'] ? 'true' : 'false' }}"
                                x-bind:aria-pressed="$wire.tone === @js($option['value']) ? 'true' : 'false'"
                                x-on:click="$wire.set('tone', @js($option['value']))"
                                title="{{ $option['hint'] }}"
                                @disabled(! $editable)
                            >{{ $option['label'] }}</button>
                        @endforeach
                    </div>
                    <p class="rg-admin-hint">{{ $chosenTone->hint() }} The mood of the whole conversation; each persona still writes in its own voice.</p>
                </div>
            </div>
        </x-admin.ui.card>
        {{-- The places of the Comment voters and Comment votes cards: StudioPlanDiscussionVotesEditor draws them here and keeps their state, so this editor never draws over them. --}}
        <div id="rg-admin-studio-comment-voters" class="rg-admin-studio__comment-voters-slot" wire:ignore></div>
        <div id="rg-admin-studio-comment-vote-settings" class="rg-admin-studio__comment-voters-slot" wire:ignore></div>
    </div>

    <div class="rg-admin-studio__generate" x-data @if (! $analysed && $analysing && ! $underway) wire:poll.{{ $pollSeconds }}s.visible @endif>
        <span class="rg-admin-studio__generate-icon" aria-hidden="true"><x-admin.ui.icon name="git-branch" :size="16" /></span>
        <div class="rg-admin-studio__generate-text">
            <div class="rg-admin-studio__generate-label">Generate from the settings above</div>
            <div class="rg-admin-studio__generate-summary">
                {{ $summary }}@if ($hasTree)<span class="rg-admin-studio__generate-votes"> · <span x-text="$store.rgCommentVotes?.status || @js($votesSaved)">{{ $votesSaved }}</span></span>
                    <span class="rg-admin-studio__node-unsaved" x-show="$store.rgCommentVotes?.unsaved" x-cloak>Not saved</span>@endif
            </div>
            <div @class(['rg-admin-studio__generate-hint', 'rg-admin-studio__generate-hint--danger' => $hintTone === 'danger'])>
                <span x-show="! ($store.adminEditor?.changes > 0)">{{ $hint }}</span>
                <span x-show="$store.adminEditor?.changes > 0" x-cloak>The post’s changes are saved first: the discussion is written from the saved title and description.</span>
            </div>
        </div>
        <x-admin.ui.button
            variant="primary"
            icon="sparkles"
            :disabled="$generateBlocked || $titleOnly"
            x-bind:disabled="{{ $generateBlocked ? 'true' : 'false' }} || ({{ $titleOnly ? 'true' : 'false' }} && ! ($store.adminEditor?.changes > 0))"
            {{-- The post changed and not saved: the plan's editor saves it first, then says to generate (GENERATE_EVENT). --}}
            x-on:click="$store.adminEditor?.changes > 0 ? $dispatch('save-studio-plan', { then: 'generate' }) : $wire.generateTree(0)"
        >{{ $hasTree ? 'Regenerate tree' : 'Generate tree' }}</x-admin.ui.button>
    </div>

    @if ($failure !== null)
        <x-admin.ui.inline-notice tone="warning">
            The tree wasn’t generated: {{ $failure }}
            @if ($editable)
                <x-slot:actions>
                    <x-admin.ui.button size="sm" icon="refresh-cw" x-on:click="$wire.generateTree($store.adminEditor?.changes ?? 0)">Try again</x-admin.ui.button>
                </x-slot:actions>
            @endif
        </x-admin.ui.inline-notice>
    @endif

    @if ($staleTexts && ! $busy)
        <x-admin.ui.inline-notice tone="info">
            The discussion was generated for an earlier title or description. It isn’t regenerated automatically.
            @if ($editable)
                <x-slot:actions>
                    <x-admin.ui.button size="sm" :disabled="$generateBlocked" x-bind:disabled="{{ $generateBlocked ? 'true' : 'false' }} || $store.adminEditor?.changes > 0" x-on:click="$wire.generateTree($store.adminEditor?.changes ?? 0)">Regenerate</x-admin.ui.button>
                    <x-admin.ui.button size="sm" wire:click="keepDiscussion">Keep as is</x-admin.ui.button>
                </x-slot:actions>
            @endif
        </x-admin.ui.inline-notice>
    @endif

    {{-- The tree, and beside it the breakdown of the comment opened from it, which the votes editor draws in the place kept for it. --}}
    <div class="rg-admin-studio__tree-area">
    <div class="rg-admin-studio__tree-layout">
    <x-admin.ui.card title="Discussion tree" flush :description="count($rows).' '.(count($rows) === 1 ? 'comment' : 'comments').' · '.$rootTotal.' top-level'" class="rg-admin-studio__tree-card">
        <x-slot:aside>
            <x-admin.ui.button size="sm" icon="plus" :disabled="! $editable || $underway" wire:click="openNew">Comment</x-admin.ui.button>
        </x-slot:aside>

        <x-admin.ui.tabs
            label="Discussion tree"
            class="rg-admin-studio__tree-tabs"
            :active="$tab"
            :items="[
                ['id' => 'tree', 'label' => 'Comments', 'count' => count($rows), 'attributes' => ['wire:click' => '$set(\'tab\', \'tree\')']],
                ['id' => 'voters', 'label' => 'Voters', 'attributes' => ['wire:click' => '$set(\'tab\', \'voters\')']],
                ['id' => 'timing', 'label' => 'Timing', 'attributes' => ['wire:click' => '$set(\'tab\', \'timing\')']],
            ]"
        />

        @if ($busy)
            <div class="rg-admin-studio__tree-busy" role="status" aria-label="Generating the discussion">
                @foreach ([0, 1, 1, 0, 1, 0] as $depth)
                    <x-admin.ui.skeleton height="64px" shape="box" @class(['rg-admin-studio__tree-placeholder', 'rg-admin-studio__tree-placeholder--reply' => $depth === 1]) />
                @endforeach
            </div>
        @elseif (! $hasTree)
            <div class="rg-admin-studio__tree-empty">
                <x-admin.ui.empty-state :title="$totalCount === 0 ? 'No discussion planned' : 'No discussion yet'" icon="message-square">
                    {{ $totalCount === 0 ? 'The post publishes without automated comments.' : 'Set the settings above and generate the tree. You can edit every comment afterwards.' }}
                </x-admin.ui.empty-state>
            </div>
        @elseif ($tab === 'tree')
            <ol class="rg-admin-studio__tree" aria-label="Comments">
                @foreach ($rows as $row)
                    <li @class(['rg-admin-studio__node', 'rg-admin-studio__node--reply' => $row['reply']]) wire:key="discussion-node-{{ $row['id'] }}" data-node="{{ $row['label'] }}" x-bind:class="{ 'rg-admin-studio__node--selected': $store.rgCommentVotes?.selected === {{ $row['id'] }} }">
                        <div class="rg-admin-studio__node-box">
                            @include('livewire.admin.studio-plan-discussion-avatar', ['persona' => $row['persona']])
                            <div class="rg-admin-studio__node-main">
                                <div class="rg-admin-studio__node-head">
                                    <span class="rg-admin-studio__node-name">{{ $row['persona']['name'] ?? 'A persona' }}</span>
                                    <span class="rg-admin-studio__node-automated"><x-admin.ui.icon name="bot" :size="11" />Automated</span>
                                    <span class="rg-admin-studio__mono rg-admin-studio__muted">{{ $row['label'] }}</span>
                                    @if ($row['reply'])
                                        <span class="rg-admin-studio__muted">↳ {{ $row['replyTo'] }}</span>
                                    @endif
                                    @if (($row['persona']['problem'] ?? null) === 'deleted')
                                        <x-admin.ui.badge tone="danger">Deleted</x-admin.ui.badge>
                                    @elseif (($row['persona']['problem'] ?? null) === 'not_persona')
                                        <x-admin.ui.badge tone="danger">Not a persona</x-admin.ui.badge>
                                    @elseif (($row['persona']['problem'] ?? null) === 'restricted' && ($row['persona']['status'] ?? null) !== null)
                                        <x-admin.ui.badge :tone="$row['persona']['status']['tone']" :dot="$row['persona']['status']['dot']">{{ $row['persona']['status']['label'] }}</x-admin.ui.badge>
                                    @endif
                                    <span class="rg-admin-studio__node-stats">
                                        <span class="rg-admin-studio__node-time"><x-admin.ui.icon name="clock" :size="12" />{{ $row['offset'] }}</span>
                                        <span class="rg-admin-studio__node-vote" title="Up votes prepared on {{ $row['label'] }}"><x-admin.ui.icon name="thumbs-up" :size="12" label="Up votes" /><span x-text="$store.rgCommentVotes?.nodes?.[{{ $row['id'] }}]?.up ?? {{ $row['up'] }}">{{ $row['up'] }}</span></span>
                                        <span class="rg-admin-studio__node-vote" title="Down votes prepared on {{ $row['label'] }}"><x-admin.ui.icon name="thumbs-down" :size="12" label="Down votes" /><span x-text="$store.rgCommentVotes?.nodes?.[{{ $row['id'] }}]?.down ?? {{ $row['down'] }}">{{ $row['down'] }}</span></span>
                                        <span class="rg-admin-studio__node-unsaved" x-show="$store.rgCommentVotes?.nodes?.[{{ $row['id'] }}]?.changed" x-cloak>Not saved</span>
                                        <span class="rg-admin-studio__node-problem" x-show="$store.rgCommentVotes?.nodes?.[{{ $row['id'] }}]?.problem" x-bind:title="$store.rgCommentVotes?.nodes?.[{{ $row['id'] }}]?.problem ?? ''" x-cloak><x-admin.ui.icon name="circle-alert" :size="13" label="Its votes need a look" /></span>
                                    </span>
                                </div>
                                <div @class(['rg-admin-studio__node-body', 'rg-admin-studio__node-body--rewriting' => $row['rewriting']])>{{ $row['body'] }}</div>
                                <div class="rg-admin-studio__node-actions">
                                    @if ($editable)
                                        @unless ($row['reply'])
                                            <button type="button" class="rg-admin-studio__node-action" wire:click="openReply({{ $row['id'] }})" @disabled($underway)><x-admin.ui.icon name="reply" :size="13" />Reply</button>
                                        @endunless
                                        <button type="button" class="rg-admin-studio__node-action" wire:click="openEdit({{ $row['id'] }})" @disabled($underway)><x-admin.ui.icon name="pencil" :size="13" />Edit</button>
                                        <button type="button" class="rg-admin-studio__node-action" x-on:click="$wire.regenerate({{ $row['id'] }}, $store.adminEditor?.changes ?? 0)" @disabled($rewriteBlocked) @if ($notReady !== null) title="{{ $notReady }}" @endif>
                                            <x-admin.ui.icon :name="$row['rewriting'] ? 'loader' : 'refresh-cw'" :size="13" />{{ $row['rewriting'] ? 'Rewriting…' : 'Regenerate' }}
                                        </button>
                                        <button type="button" class="rg-admin-studio__node-action rg-admin-studio__node-action--danger" wire:click="askDelete({{ $row['id'] }})" @disabled($underway)><x-admin.ui.icon name="trash-2" :size="13" />Delete</button>
                                    @endif
                                    <button
                                        type="button"
                                        class="rg-admin-studio__node-action rg-admin-studio__node-breakdown"
                                        aria-controls="rg-admin-studio-comment-breakdown"
                                        aria-expanded="false"
                                        x-bind:aria-expanded="$store.rgCommentVotes?.selected === {{ $row['id'] }} ? 'true' : 'false'"
                                        x-on:click="$dispatch(@js(\App\Livewire\Admin\StudioPlanDiscussionVotesEditor::SELECT_EVENT), { node: {{ $row['id'] }} })"
                                    ><x-admin.ui.icon name="panel-right-open" :size="13" />See breakdown</button>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
        {{-- The Voters and Timing tabs: drawn by the votes editor in the places kept for them, shown while their tab is on screen. --}}
        <div class="rg-admin-studio__tree-voters" @if ($busy || ! $hasTree || $tab !== 'voters') hidden @endif>
            <div id="rg-admin-studio-comment-voters-table" wire:ignore></div>
        </div>
        <div class="rg-admin-studio__tree-timing" @if ($busy || ! $hasTree || $tab !== 'timing') hidden @endif>
            <div id="rg-admin-studio-timing" wire:ignore></div>
        </div>
    </x-admin.ui.card>
    <div id="rg-admin-studio-comment-breakdown" class="rg-admin-studio__breakdown-slot" wire:ignore></div>
    </div>
    </div>

    @if ($dialogData !== null)
        <x-admin.ui.overlay class="rg-admin-dialog-layer" x-on:dismiss="$wire.closeDialog()" x-on:mousedown.self="if ($event.offsetX < $el.clientWidth) dismiss()">
            <div class="rg-admin-dialog rg-admin-studio__comment-dialog" role="dialog" aria-modal="true" aria-labelledby="rg-admin-discussion-dialog-title" tabindex="-1" x-trap.inert.noscroll="! closing">
                <div class="rg-admin-studio__dialog-header">
                    <h2 id="rg-admin-discussion-dialog-title" class="rg-admin-dialog__title">{{ $dialogData['title'] }}</h2>
                    <x-admin.ui.icon-button icon="x" label="Close" variant="ghost" size="sm" x-on:click="dismiss()" />
                </div>

                <div class="rg-admin-studio__dialog-body">
                    <div class="rg-admin-field">
                        <span class="rg-admin-label" id="rg-admin-discussion-dialog-persona">Persona</span>
                        <x-admin.ui.search-field placeholder="Search by login or name" wire:model.live.debounce.300ms="dialogSearch" autocomplete="off" />
                        <div class="rg-admin-studio__persona-chips" role="radiogroup" aria-labelledby="rg-admin-discussion-dialog-persona">
                            @foreach ($dialogData['personas'] as $persona)
                                <button type="button" role="radio" aria-checked="{{ $persona['on'] ? 'true' : 'false' }}" class="rg-admin-studio__persona-chip" wire:key="discussion-dialog-persona-{{ $persona['id'] }}" wire:click="choosePersona({{ $persona['id'] }})">
                                    <span class="rg-admin-studio__persona-chip-initials" aria-hidden="true">{{ $persona['initials'] ?? '' }}</span>{{ $persona['name'] }}
                                </button>
                            @endforeach
                        </div>
                        @error('dialogPersona')
                            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @enderror
                    </div>

                    <div class="rg-admin-field" x-data="{ open: false }" x-on:click.outside="open = false">
                        <span class="rg-admin-label" id="rg-admin-discussion-dialog-parent">Replies to</span>
                        <div class="rg-admin-studio__parent">
                            <button type="button" class="rg-admin-studio__parent-trigger" aria-haspopup="listbox" aria-labelledby="rg-admin-discussion-dialog-parent" x-bind:aria-expanded="open" x-on:click="open = ! open" @disabled($dialogData['hasReplies'])>
                                <span class="rg-admin-studio__parent-label">{{ $dialogData['parentLabel'] }}</span>
                                <x-admin.ui.icon name="chevron-down" :size="16" />
                            </button>
                            <div class="rg-admin-studio__parent-menu" role="listbox" x-show="open" x-cloak>
                                <button type="button" role="option" aria-selected="{{ $dialogData['parentRef'] === null ? 'true' : 'false' }}" class="rg-admin-studio__parent-option" x-on:click="open = false; $wire.chooseParent(null)">
                                    <span class="rg-admin-studio__parent-option-label">No parent</span>
                                    <span class="rg-admin-studio__parent-option-sub">Top-level comment</span>
                                </button>
                                @foreach ($dialogData['parents'] as $parent)
                                    <button type="button" role="option" aria-selected="{{ $parent['on'] ? 'true' : 'false' }}" class="rg-admin-studio__parent-option" wire:key="discussion-dialog-parent-{{ $parent['id'] }}" x-on:click="open = false; $wire.chooseParent({{ $parent['id'] }})">
                                        <span class="rg-admin-studio__parent-option-label">{{ $parent['label'] }}</span>
                                        <span class="rg-admin-studio__parent-option-sub">{{ $parent['sub'] }}</span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                        @if ($dialogData['hasReplies'])
                            <p class="rg-admin-hint">It has replies, so it stays a top-level comment.</p>
                        @endif
                        @error('dialogParent')
                            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @enderror
                    </div>

                    <x-admin.ui.textarea
                        label="Text"
                        id="rg-admin-discussion-dialog-text"
                        placeholder="Write the comment in English…"
                        :rows="4"
                        :value="$dialogBody"
                        :limit="\App\Support\Studio\StudioDiscussionValidationRules::BODY_LIMIT"
                        :error="$errors->first('dialogBody') ?: null"
                        wire:model="dialogBody"
                    />

                    <div
                        class="rg-admin-field"
                        wire:key="discussion-dialog-time-{{ $dialogData['parentRef'] ?? 'post' }}"
                        x-data="{
                            units: @js($units),
                            parent: @js($dialogData['parentOffset']),
                            ref: @js($dialogData['parentRef']),
                            get unit() { return this.units[$wire.dialogUnit] ? $wire.dialogUnit : 'min' },
                            get seconds() { return Number($wire.dialogSeconds) || 0 },
                            shown() { return String(Math.round((this.seconds / this.units[this.unit]) * 100) / 100) },
                            pick(unit) {
                                $wire.$set('dialogUnit', unit, false)
                                this.$nextTick(() => { this.$refs.offset.value = this.shown() })
                            },
                            set(input) {
                                const n = Number.parseFloat(String(input.value).replace(',', '.'))

                                if (Number.isNaN(n) || n < 0) return

                                $wire.$set('dialogSeconds', Math.round(n * this.units[this.unit]), false)
                            },
                            rel(seconds) {
                                const m = Math.round(seconds / 60)

                                if (m < 60) return m + ' min'
                                if (m < 1440) return Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '')

                                return Math.floor(m / 1440) + ' d' + (Math.floor((m % 1440) / 60) ? ' ' + Math.floor((m % 1440) / 60) + ' h' : '')
                            },
                            get early() { return this.parent !== null && this.seconds < this.parent },
                            get hint() {
                                if (this.early) return 'Earlier than its parent (' + this.ref + ' at +' + this.rel(this.parent) + '). A reply can’t come before the comment it answers.'

                                return '+' + this.rel(this.seconds) + ' after the post' + (this.parent !== null ? ' · ' + this.rel(this.seconds - this.parent) + ' after ' + this.ref : '')
                            },
                        }"
                    >
                        <label for="rg-admin-discussion-dialog-offset" class="rg-admin-label">Appears after the post</label>
                        <div class="rg-admin-studio__offset rg-admin-studio__offset--large">
                            <input id="rg-admin-discussion-dialog-offset" type="text" inputmode="decimal" class="rg-admin-studio__offset-input" x-ref="offset" x-init="$el.value = shown()" x-on:input="set($event.target)" />
                            <div class="rg-admin-studio__units" role="radiogroup" aria-label="Unit">
                                @foreach (array_keys($units) as $unit)
                                    <button type="button" role="radio" class="rg-admin-studio__unit" x-bind:aria-checked="unit === @js($unit)" x-on:click="pick(@js($unit))">{{ $unit }}</button>
                                @endforeach
                            </div>
                        </div>
                        <p class="rg-admin-hint" x-bind:class="early && 'rg-admin-studio__generate-hint--danger'" x-text="hint"></p>
                        @error('dialogSeconds')
                            <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @enderror
                    </div>

                    @error('dialog')
                        <x-admin.ui.inline-notice tone="warning">{{ $message }}</x-admin.ui.inline-notice>
                    @enderror
                </div>

                <div class="rg-admin-dialog__footer">
                    <span class="rg-admin-dialog__footnote">Saved to this draft at once. Nothing is published.</span>
                    <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                    <x-admin.ui.button variant="primary" wire:click="saveDialog" x-bind:disabled="! String($wire.dialogBody ?? '').trim()">Save</x-admin.ui.button>
                </div>
            </div>
        </x-admin.ui.overlay>
    @endif

    @if ($deletingRow !== null)
        <x-admin.ui.confirm-dialog
            :title="$deletingRow['replies'] > 0 ? 'Delete '.$deletingRow['label'].' and its '.$deletingRow['replies'].' '.($deletingRow['replies'] === 1 ? 'reply' : 'replies').'?' : 'Delete '.$deletingRow['label'].'?'"
            tone="danger"
            icon="trash-2"
            id="rg-admin-discussion-delete"
            x-on:dismiss="$wire.closeConfirmation()"
        >
            {{ $deletingRow['replies'] > 0 ? 'Replies can’t stay without their parent, so the whole branch leaves the plan.' : 'The comment leaves the plan.' }}
            <x-slot:details>
                <div class="rg-admin-dialog-subject">
                    <span class="rg-admin-dialog-subject__icon"><x-admin.ui.icon name="message-square" :size="16" /></span>
                    <span class="rg-admin-dialog-subject__text">
                        <span class="rg-admin-dialog-subject__title">{{ $deletingRow['body'] }}</span>
                        <span class="rg-admin-dialog-subject__meta">{{ $deletingRow['meta'] }}</span>
                    </span>
                </div>
                @if ($deletingRow['replies'] > 0)
                    <ul class="rg-admin-dialog-facts">
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="git-branch" :size="16" />{{ $deletingRow['replies'] + 1 }} comments removed: {{ $deletingRow['labels'] }}</li>
                    </ul>
                @endif
            </x-slot:details>
            <x-slot:footnote>You can undo it from the notice that follows.</x-slot:footnote>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="danger" icon="trash-2" x-on:click="hide(); $wire.confirmDelete()">{{ $deletingRow['replies'] > 0 ? 'Delete branch' : 'Delete comment' }}</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif

    @if ($confirmingGenerate)
        <x-admin.ui.confirm-dialog title="Replace the discussion?" tone="warning" icon="refresh-cw" id="rg-admin-discussion-replace" x-on:dismiss="$wire.closeConfirmation()">
            A new tree replaces every comment: its text, persona and timing.
            <x-slot:details>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="message-square" :size="16" />{{ count($rows) }} current {{ count($rows) === 1 ? 'comment is' : 'comments are' }} replaced</li>
                </ul>
            </x-slot:details>
            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" icon="refresh-cw" x-on:click="hide(); $wire.confirmGenerate($store.adminEditor?.changes ?? 0)">Replace discussion</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
