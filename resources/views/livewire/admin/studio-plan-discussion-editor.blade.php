{{--
    The Discussion step (StudioPlanDiscussionEditor). Discussion settings: how
    many comments, who writes them — chosen one by one, or a team picked at
    random by Pick personas and shown first — and the tone; Generate tree, an
    explicit paid request. The generations and their proposals
    (StudioPlanDiscussionProposals), read again on their own while one is on
    its way. Then the prepared discussion: each comment on the post with its
    replies under it, who writes it and when, edited here as a draft of its
    own and kept with Save discussion — the top bar's Save draft saves the
    post, never this. Every text is drawn escaped.

    The browser is told whether the draft differs from what is saved
    (`discussionDirty`), so leaving the page with unsaved comments asks first.
--}}
@php
    $blocked = ! $editable;
    $full = $count >= $mostNodes;
    $notReady = match (true) {
        ! $configured => 'Discussion generation is not configured. Comments can still be written by hand.',
        ! $hasTitle => 'Save the post’s final title first: the discussion is written from it.',
        ! $analysed => 'The discussion can be written once the photo’s analysis is complete.',
        default => null,
    };
@endphp

<div
    class="rg-admin-studio__discussion"
    data-discussion="{{ $dirty ? 'unsaved' : 'saved' }}"
    x-data
    x-effect="if ($store.adminEditor) { $store.adminEditor.discussionDirty = JSON.stringify($wire.nodes) !== JSON.stringify($wire.savedNodes) }"
>
    @if ($gone)
        <x-admin.ui.inline-notice tone="warning">This draft was deleted, so its discussion can’t be saved any more.</x-admin.ui.inline-notice>
    @elseif ($closed)
        <x-admin.ui.inline-notice tone="info">This plan is no longer a draft, so its discussion can’t be changed here.</x-admin.ui.inline-notice>
    @elseif ($conflict)
        <x-admin.ui.inline-notice tone="warning">
            Someone changed this draft after you opened it, so nothing here can be saved. Reload to see their version — it replaces your unsaved comments here.
            <x-slot:actions>
                <x-admin.ui.button size="sm" icon="refresh-cw" wire:click="reload">Reload discussion</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.inline-notice>
    @endif

    <x-admin.ui.card title="Discussion settings" description="Comments are written in English from each persona’s character. The result is a proposal: nothing changes until you apply it, and nothing is published.">
        <div class="rg-admin-studio__discussion-settings">
            <div class="rg-admin-studio__discussion-counts">
                <x-admin.ui.number-stepper
                    label="Total comments"
                    id="rg-admin-discussion-total"
                    :value="$totalCount"
                    :min="1"
                    :max="$mostGenerated"
                    hint="Comments and replies together, up to {{ $mostGenerated }}."
                    wire:model.live.debounce.400ms="totalCount"
                />
                <x-admin.ui.number-stepper
                    label="Top-level comments"
                    id="rg-admin-discussion-roots"
                    :value="$rootCount"
                    :min="1"
                    :max="$totalCount"
                    hint="Comments on the post. The rest are replies."
                    wire:model.live.debounce.400ms="rootCount"
                />
            </div>

            <div class="rg-admin-studio__discussion-part">
                <span class="rg-admin-label" id="rg-admin-discussion-personas-label">Personas</span>
                <x-admin.ui.segmented
                    label="How the personas are chosen"
                    class="rg-admin-studio__segmented"
                    :options="['manual' => 'Manual', 'random' => 'Random']"
                    :value="$participantMode"
                    wire:model.live="participantMode"
                />
                <p class="rg-admin-hint">
                    @if ($participantMode === 'random')
                        Pick personas chooses {{ $randomCount }} at random from those not deleted. You see the team before anything is generated.
                    @else
                        Only the personas you choose take part — up to {{ $mostPersonas }}.
                    @endif
                </p>

                @if ($participantMode === 'random')
                    <div class="rg-admin-studio__discussion-pick">
                        <x-admin.ui.number-stepper
                            label="Number of personas"
                            id="rg-admin-discussion-random-count"
                            :value="$randomCount"
                            :min="1"
                            :max="$mostPersonas"
                            wire:model.live.debounce.400ms="randomCount"
                        />
                        <x-admin.ui.button icon="users" wire:click="pickPersonas" wire:loading.attr="disabled" wire:target="pickPersonas" :disabled="$blocked">
                            {{ $participantsShown === [] ? 'Pick personas' : 'Pick again' }}
                        </x-admin.ui.button>
                    </div>
                @else
                    <x-admin.ui.search-field
                        placeholder="Search personas"
                        name="participantSearch"
                        id="rg-admin-discussion-participant-search"
                        :value="$participantSearch"
                        wire:model.live.debounce.300ms="participantSearch"
                    />
                    @if ($participantChoices !== [])
                        <ul class="rg-admin-studio__choices" aria-label="Personas to add">
                            @foreach ($participantChoices as $choice)
                                <li wire:key="participant-choice-{{ $choice['id'] }}">
                                    <button type="button" class="rg-admin-studio__choice" wire:click="toggleParticipant({{ $choice['id'] }})" @disabled($blocked)>
                                        @include('livewire.admin.studio-plan-discussion-persona', ['persona' => $choice])
                                        <x-admin.ui.icon name="plus" :size="14" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif

                @if ($participantsShown !== [])
                    <ul class="rg-admin-studio__chips" aria-label="Taking part">
                        @foreach ($participantsShown as $persona)
                            <li class="rg-admin-filter-chip" wire:key="participant-{{ $persona['id'] }}">
                                {{ $persona['name'] }}
                                @if ($participantMode === 'manual')
                                    <button type="button" class="rg-admin-filter-chip__remove" wire:click="toggleParticipant({{ $persona['id'] }})" aria-label="Remove {{ $persona['name'] }}" @disabled($blocked)>
                                        <x-admin.ui.icon name="x" :size="12" />
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @if ($participantMode === 'manual')
                        <div><x-admin.ui.button size="sm" variant="ghost" wire:click="clearParticipants" :disabled="$blocked">Clear</x-admin.ui.button></div>
                    @endif
                    <p class="rg-admin-hint">{{ count($participantsShown) }} of {{ $mostPersonas }} personas{{ $participantMode === 'random' ? ' picked' : ' chosen' }}.</p>
                @endif

                @error('participants')
                    <p class="rg-admin-error" role="alert"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                @enderror
            </div>

            <div class="rg-admin-studio__discussion-part">
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
                        >{{ $option['label'] }}</button>
                    @endforeach
                </div>
                <p class="rg-admin-hint">The tone sets the mood; each persona still writes in its own voice. It is used for the whole tree and for Regenerate.</p>
            </div>

            <div class="rg-admin-studio__discussion-part rg-admin-studio__discussion-generate">
                @if ($notReady !== null)
                    <x-admin.ui.inline-notice tone="info">{{ $notReady }}</x-admin.ui.inline-notice>
                @else
                    <p class="rg-admin-hint rg-admin-studio__context-warning" x-cloak x-show="$store.adminEditor?.changes > 0">The post has unsaved changes. Save the draft first: the discussion is written from what is saved.</p>
                    @if ($dirty)
                        <p class="rg-admin-hint rg-admin-studio__context-warning">The discussion has unsaved changes. Save or discard them first.</p>
                    @endif
                    @if ($underway)
                        <p class="rg-admin-hint" role="status">A generation is on its way — see below.</p>
                    @endif
                @endif
                <div>
                    <x-admin.ui.button
                        :variant="$generated || $count > 0 ? 'secondary' : 'primary'"
                        icon="sparkles"
                        :disabled="$blocked || $notReady !== null || $underway || $dirty"
                        x-bind:disabled="{{ ($blocked || $notReady !== null || $underway || $dirty) ? 'true' : 'false' }} || $store.adminEditor?.changes > 0"
                        x-on:click="$wire.generateTree($store.adminEditor?.changes ?? 0)"
                        wire:loading.attr="disabled"
                        wire:target="generateTree"
                        aria-describedby="rg-admin-discussion-generate-note"
                    >{{ $generated || $count > 0 ? 'Regenerate tree' : 'Generate tree' }}</x-admin.ui.button>
                </div>
                <p id="rg-admin-discussion-generate-note" class="rg-admin-hint">
                    One request to OpenAI for {{ $totalCount }} {{ $totalCount === 1 ? 'comment' : 'comments' }}, {{ $rootCount }} top-level — a paid call.
                    A new tree is shown as a proposal; applying it replaces the whole prepared discussion.
                </p>
            </div>
        </div>
    </x-admin.ui.card>

    <livewire:admin.studio-plan-discussion-proposals :plan-id="$planId" :wire:key="'studio-plan-discussion-proposals-'.$planId" />

    <x-admin.ui.card title="Prepared discussion" description="Save discussion keeps these comments. Save draft in the top bar saves the post and its publication, not this discussion — each is saved on its own.">
        <x-slot:aside>
            <span class="rg-admin-studio__discussion-meta">
                {{ $count }} of {{ $mostNodes }}
                @if ($dirty)
                    <x-admin.ui.badge tone="warning" :dot="true">Unsaved changes</x-admin.ui.badge>
                @endif
            </span>
        </x-slot:aside>

        @error('discussion')
            <x-admin.ui.inline-notice tone="warning">{{ $message }}</x-admin.ui.inline-notice>
        @enderror

        @if ($rows === [])
            <x-admin.ui.empty-state icon="message-square" title="No discussion yet" :heading-level="3">
                Add a comment, or generate a tree. A post may be published with no discussion at all.
            </x-admin.ui.empty-state>
        @else
            <ol class="rg-admin-studio__thread" aria-label="Prepared discussion">
                @foreach ($rows as $row)
                    @php
                        $key = $row['key'];
                        $errorsHere = collect(['body', 'personaUserId', 'offsetSeconds', 'parentId', 'parentKey', 'id'])
                            ->map(fn (string $field): ?string => $errors->first("nodes.{$key}.{$field}") ?: null)
                            ->filter()
                            ->values();
                    @endphp
                    <li
                        wire:key="discussion-node-{{ $key }}"
                        @class([
                            'rg-admin-studio__comment',
                            'rg-admin-studio__comment--reply' => $row['reply'],
                            'rg-admin-studio__comment--changed' => $row['changed'],
                            'rg-admin-studio__comment--invalid' => $errorsHere->isNotEmpty(),
                        ])
                        data-node="{{ $key }}"
                    >
                        <div class="rg-admin-studio__comment-head">
                            @include('livewire.admin.studio-plan-discussion-persona', ['persona' => $row['persona'], 'missing' => 'Choose a persona'])
                            <span class="rg-admin-studio__comment-label rg-admin-mono">{{ $row['label'] }}</span>
                            <span class="rg-admin-studio__comment-time" title="{{ $row['at'] !== null ? $row['at'].' ('.$timezone.')' : 'After the post is published' }}">
                                {{ $row['relative'] }}{{ $row['sinceParent'] !== null ? ' · '.$row['sinceParent'].' after its comment' : '' }}
                            </span>
                            @if (! $row['saved'])
                                <x-admin.ui.badge tone="info">New</x-admin.ui.badge>
                            @elseif ($row['changed'])
                                <x-admin.ui.badge tone="warning">Edited</x-admin.ui.badge>
                            @endif
                        </div>

                        <p class="rg-admin-studio__comment-body">@if ($row['body'] !== ''){{ $row['body'] }}@else<span class="rg-admin-studio__empty">No text yet</span>@endif</p>
                        @if ($row['at'] !== null)
                            <p class="rg-admin-hint">Comes {{ $row['at'] }} ({{ $timezone }}), as the post is dated now.</p>
                        @endif

                        @foreach ($errorsHere as $message)
                            <p class="rg-admin-error" role="alert"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $message }}</p>
                        @endforeach

                        @unless ($blocked)
                            <div class="rg-admin-studio__comment-actions">
                                @unless ($row['reply'])
                                    <x-admin.ui.button size="sm" variant="ghost" icon="message-square" wire:click="addReply('{{ $key }}')" :disabled="$full">Reply</x-admin.ui.button>
                                @endunless
                                <x-admin.ui.button size="sm" variant="ghost" icon="pencil" wire:click="edit('{{ $key }}')" :aria-expanded="$editing === $key ? 'true' : 'false'">{{ $editing === $key ? 'Done' : 'Edit' }}</x-admin.ui.button>
                                <x-admin.ui.button size="sm" variant="ghost" icon="refresh-cw" wire:click="openRegenerate('{{ $key }}')" :aria-expanded="$regenerating === $key ? 'true' : 'false'">Regenerate</x-admin.ui.button>
                                <x-admin.ui.button size="sm" variant="ghost" icon="chevron-up" wire:click="moveUp('{{ $key }}')" :disabled="$row['first']" aria-label="Move up">Up</x-admin.ui.button>
                                <x-admin.ui.button size="sm" variant="ghost" icon="chevron-down" wire:click="moveDown('{{ $key }}')" :disabled="$row['last']" aria-label="Move down">Down</x-admin.ui.button>
                                <x-admin.ui.button size="sm" variant="ghost" icon="trash-2" wire:click="delete('{{ $key }}')">Delete</x-admin.ui.button>
                            </div>
                        @endunless

                        @if ($regenerating === $key && ! $blocked)
                            <div class="rg-admin-studio__comment-panel" role="group" aria-label="Regenerate {{ $row['label'] }}">
                                @if (! $row['saved'] || $row['changed'] || $dirty)
                                    <x-admin.ui.inline-notice tone="info">Save the discussion first: only a saved, unchanged comment can be written again.</x-admin.ui.inline-notice>
                                @elseif ($notReady !== null)
                                    <x-admin.ui.inline-notice tone="info">{{ $notReady }}</x-admin.ui.inline-notice>
                                @else
                                    <p class="rg-admin-hint">Written again by the same persona, at the same time and place, in the {{ $chosenTone->label() }} tone. You see it as a proposal first. A paid call.</p>
                                    <div class="rg-admin-studio__comment-actions">
                                        @if ($row['reply'])
                                            <x-admin.ui.button size="sm" icon="sparkles" :disabled="$underway" x-on:click="$wire.regenerate('{{ $key }}', 'reply', $store.adminEditor?.changes ?? 0)">Rewrite this reply</x-admin.ui.button>
                                        @else
                                            <x-admin.ui.button size="sm" icon="sparkles" :disabled="$underway" x-on:click="$wire.regenerate('{{ $key }}', 'comment', $store.adminEditor?.changes ?? 0)">Rewrite this comment</x-admin.ui.button>
                                            @if ($row['replies'] > 0)
                                                <x-admin.ui.button size="sm" icon="git-branch" :disabled="$underway" x-on:click="$wire.regenerate('{{ $key }}', 'branch', $store.adminEditor?.changes ?? 0)">Rewrite the whole branch</x-admin.ui.button>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endif

                        @if ($editing === $key && ! $blocked)
                            <div class="rg-admin-studio__comment-panel" role="group" aria-label="Edit {{ $row['label'] }}">
                                <div class="rg-admin-studio__comment-persona">
                                    <span class="rg-admin-label">Persona</span>
                                    <div class="rg-admin-studio__comment-chosen">
                                        @include('livewire.admin.studio-plan-discussion-persona', ['persona' => $row['persona'], 'missing' => 'No persona chosen'])
                                    </div>
                                    <x-admin.ui.search-field
                                        placeholder="Search personas"
                                        name="personaSearch"
                                        id="rg-admin-discussion-persona-search"
                                        :value="$personaSearch"
                                        wire:model.live.debounce.300ms="personaSearch"
                                    />
                                    @if ($personaChoices !== [])
                                        <ul class="rg-admin-studio__choices" aria-label="Personas to choose">
                                            @foreach ($personaChoices as $choice)
                                                <li wire:key="persona-choice-{{ $key }}-{{ $choice['id'] }}">
                                                    <button type="button" class="rg-admin-studio__choice" wire:click="choosePersona('{{ $key }}', {{ $choice['id'] }})">
                                                        @include('livewire.admin.studio-plan-discussion-persona', ['persona' => $choice])
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>

                                <x-admin.ui.textarea
                                    label="Comment"
                                    id="rg-admin-discussion-body-{{ $key }}"
                                    :value="$row['body']"
                                    :rows="3"
                                    :limit="$bodyLimit"
                                    :required="true"
                                    wire:model="nodes.{{ $key }}.body"
                                    :error="$errors->first('nodes.'.$key.'.body') ?: null"
                                />

                                <div
                                    class="rg-admin-studio__comment-timing"
                                    x-data="{
                                        d: {{ intdiv($row['offset'], 86400) }},
                                        h: {{ intdiv($row['offset'] % 86400, 3600) }},
                                        m: {{ intdiv($row['offset'] % 3600, 60) }},
                                        s: {{ $row['offset'] % 60 }},
                                        latest: {{ $latestOffset }},
                                        parent: @js($row['parentOffset']),
                                        postAt: @js($postAt),
                                        zone: @js($timezone),
                                        get total() { return Math.min(this.latest, Math.max(0, (Number(this.d) || 0) * 86400 + (Number(this.h) || 0) * 3600 + (Number(this.m) || 0) * 60 + (Number(this.s) || 0))) },
                                        label(seconds) {
                                            const parts = [[Math.floor(seconds / 86400), 'd'], [Math.floor(seconds % 86400 / 3600), 'h'], [Math.floor(seconds % 3600 / 60), 'min'], [seconds % 60, 's']].filter(([n]) => n > 0).map(([n, unit]) => `${n} ${unit}`)

                                            return parts.length ? parts.join(' ') : '0 min'
                                        },
                                        sync() { $wire.$set('nodes.{{ $key }}.offsetSeconds', this.total, false) },
                                        at() {
                                            if (! this.postAt) return null

                                            return new Intl.DateTimeFormat('en-GB', { timeZone: this.zone, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(Date.parse(this.postAt) + this.total * 1000))
                                        },
                                    }"
                                >
                                    <span class="rg-admin-label">When it comes, after the post</span>
                                    <div class="rg-admin-studio__comment-units">
                                        @foreach (['d' => ['Days', 30], 'h' => ['Hours', 23], 'm' => ['Minutes', 59], 's' => ['Seconds', 59]] as $unit => [$unitLabel, $unitMax])
                                            <label class="rg-admin-studio__comment-unit">
                                                <span class="rg-admin-hint">{{ $unitLabel }}</span>
                                                <input type="number" min="0" max="{{ $unitMax }}" step="1" class="rg-admin-input" x-model.number="{{ $unit }}" x-on:input="sync()" />
                                            </label>
                                        @endforeach
                                    </div>
                                    <p class="rg-admin-hint" aria-live="polite">
                                        <span x-text="'+' + label(total) + ' after the post'"></span><span x-show="parent !== null" x-text="parent !== null ? (total >= parent ? ' · ' + label(total - parent) + ' after its comment' : ' · earlier than its comment, which is not allowed') : ''"></span><span x-show="postAt" x-text="postAt ? ' · ' + at() + ' (' + zone + ')' : ''"></span>
                                    </p>
                                    @if ($errors->first('nodes.'.$key.'.offsetSeconds'))
                                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" /> {{ $errors->first('nodes.'.$key.'.offsetSeconds') }}</p>
                                    @endif
                                </div>

                                <div class="rg-admin-studio__comment-actions">
                                    <x-admin.ui.button size="sm" wire:click="closeEditor">Done</x-admin.ui.button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif

        <div class="rg-admin-studio__discussion-footer">
            <x-admin.ui.button icon="plus" wire:click="addComment" :disabled="$blocked || $full">Add comment</x-admin.ui.button>
            <span class="rg-admin-hint">
                @if ($full)
                    A discussion holds up to {{ $mostNodes }} comments and replies. Delete one to add another.
                @else
                    Two levels: comments on the post and replies to them.
                @endif
            </span>
            <span class="rg-admin-studio__discussion-footer-end">
                <x-admin.ui.button wire:click="discardChanges" :disabled="$blocked || ! $dirty">Discard changes</x-admin.ui.button>
                <x-admin.ui.button
                    variant="primary"
                    icon="save"
                    :disabled="$blocked"
                    x-bind:disabled="{{ $blocked ? 'true' : 'false' }} || ! $store.adminEditor?.discussionDirty"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >Save discussion</x-admin.ui.button>
            </span>
        </div>
    </x-admin.ui.card>

    @if ($deletingRow !== null)
        <x-admin.ui.confirm-dialog
            id="rg-admin-discussion-delete"
            tone="danger"
            icon="trash-2"
            title="Delete this comment and its {{ $deletingRow['replies'] }} {{ $deletingRow['replies'] === 1 ? 'reply' : 'replies' }}?"
            wire:key="confirm-delete-discussion-{{ $deleting }}"
            x-on:dismiss="$wire.cancelDelete()"
        >
            <p>Replies can’t stay without their parent, so the whole branch leaves the plan.</p>

            <x-slot:details>
                <div class="rg-admin-dialog-subject">
                    <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="message-square" :size="16" /></span>
                    <span class="rg-admin-dialog-subject__text">
                        <span class="rg-admin-dialog-subject__title">{{ $deletingRow['body'] !== '' ? $deletingRow['body'] : 'No text yet' }}</span>
                        <span class="rg-admin-dialog-subject__meta">{{ $deletingRow['author']['name'] ?? 'No persona' }} · removed from the draft until you save the discussion</span>
                    </span>
                </div>
            </x-slot:details>

            <x-slot:actions>
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="danger" wire:click="confirmDelete">Delete branch</x-admin.ui.button>
            </x-slot:actions>
        </x-admin.ui.confirm-dialog>
    @endif
</div>
