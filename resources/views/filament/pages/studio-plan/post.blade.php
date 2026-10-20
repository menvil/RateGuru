{{--
    Step 2 · Post. AI text suggestions first (StudioPlanTextSuggestionsCard):
    the photo and its analysis, the tone, and the newest titles and
    descriptions written from them, drawn afresh for every photo. Then what
    the post will be: its final title and description — a suggestion chosen
    lands here, unsaved, and stays editable — the Persona it is published
    as, chosen from living Personas found on the server, and its source
    address; its category and tags; and the author's own answers to the
    rating questions. Nothing here is a vote.
--}}
<div class="rg-admin-studio__post">
    <livewire:admin.studio-plan-text-suggestions-card
        :plan-id="$analysisPlanId"
        :image-asset-id="$analysisImageId"
        :wire:key="'studio-plan-text-suggestions-'.$analysisPlanId.'-'.($analysisImageId ?? 'none')"
    />

    <x-admin.ui.card title="Final texts" description="Pick any title and any description; they don’t come in pairs. Both fields stay editable, and nothing is saved until Save draft.">
        <div class="rg-admin-studio__final">
            <div class="rg-admin-studio__final-title">
                <x-admin.ui.textarea
                    label="Final title"
                    id="rg-admin-studio-title"
                    :value="$postTitle"
                    :rows="2"
                    :limit="$limits['title']"
                    :required="false"
                    placeholder="Choose a title or write one…"
                    hint="{{ $limits['titleMin'] }} to {{ $limits['title'] }} characters. A draft may leave it empty for now."
                    :error="$errors->first('title') ?: null"
                    wire:model="postTitle"
                />
            </div>
            <div class="rg-admin-studio__final-description">
                <x-admin.ui.textarea
                    label="Final description"
                    id="rg-admin-studio-description"
                    :value="$postDescription"
                    :rows="4"
                    :limit="$limits['description']"
                    :required="false"
                    placeholder="Choose a description or write one…"
                    :error="$errors->first('description') ?: null"
                    wire:model="postDescription"
                />
            </div>
            <div class="rg-admin-studio__final-author">
                {{--
                    The Persona the post is published as: the chosen one on a trigger,
                    the others found from a search in a panel under it — or over it,
                    when the bar at the window's foot leaves more room there.
                --}}
                <div
                    class="rg-admin-field"
                    x-data="{
                        open: false,
                        upward: false,
                        room: 380,
                        toggle() {
                            if (! this.open) {
                                const box = this.$refs.trigger.getBoundingClientRect()
                                const ceiling = Math.max(0, ...[...document.querySelectorAll('[data-admin-ceiling]')].map((bar) => bar.getBoundingClientRect().bottom))
                                const floor = Math.min(window.innerHeight, ...[...document.querySelectorAll('[data-admin-floor]')].map((bar) => bar.getBoundingClientRect().top))
                                const below = floor - box.bottom - 16
                                const above = box.top - ceiling - 16

                                this.upward = below < 380 && above > below
                                this.room = Math.max(160, Math.min(380, this.upward ? above : below))
                            }

                            this.open = ! this.open

                            if (this.open) {
                                this.$nextTick(() => document.getElementById('rg-admin-studio-author-search')?.focus())
                            }
                        },
                    }"
                    x-on:keydown.escape="if (open) { open = false; $refs.trigger.focus() }"
                    x-on:click.outside="open = false"
                >
                    <span class="rg-admin-label" id="rg-admin-studio-author-label">Post author</span>
                    <div class="rg-admin-studio__author-anchor">
                        <button
                            type="button"
                            class="rg-admin-studio__author-trigger"
                            x-ref="trigger"
                            aria-labelledby="rg-admin-studio-author-label rg-admin-studio-author-chosen"
                            aria-controls="rg-admin-studio-author-panel"
                            aria-expanded="false"
                            x-bind:aria-expanded="open ? 'true' : 'false'"
                            x-on:click="toggle()"
                        >
                            <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                                @if ($author !== null && $author['avatar'] !== null)
                                    <img src="{{ $author['avatar'] }}" alt="" class="rg-admin-avatar__image" />
                                @elseif ($author !== null && $author['initials'] !== '')
                                    {{ $author['initials'] }}
                                @else
                                    <x-admin.ui.icon name="user" :size="14" />
                                @endif
                            </span>
                            <span class="rg-admin-table__stack" id="rg-admin-studio-author-chosen">
                                @if ($author !== null)
                                    <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $author['name'] }}</span>
                                    @if ($author['username'] !== null)
                                        <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ '@'.$author['username'] }}</span>
                                    @endif
                                @else
                                    <span class="rg-admin-studio__empty">No author selected</span>
                                @endif
                            </span>
                            @if ($author !== null && $author['status'] !== null)
                                <x-admin.ui.badge :tone="$author['status']['tone']" :dot="$author['status']['dot']">{{ $author['status']['label'] }}</x-admin.ui.badge>
                            @endif
                            <x-admin.ui.icon name="chevrons-up-down" :size="16" class="rg-admin-studio__author-chevron" />
                        </button>

                        <div
                            class="rg-admin-studio__author-panel"
                            id="rg-admin-studio-author-panel"
                            x-show="open"
                            x-cloak
                            x-bind:class="{ 'rg-admin-studio__author-panel--up': upward }"
                            x-bind:style="{ maxHeight: `${room}px` }"
                        >
                            <x-admin.ui.search-field
                                label="Find a persona"
                                placeholder="Search personas by username or name"
                                name="authorSearch"
                                id="rg-admin-studio-author-search"
                                :value="$authorSearch"
                                wire:model.live.debounce.300ms="authorSearch"
                            />
                            @if ($authorChoices !== [])
                                <ul class="rg-admin-studio__choices" aria-label="Personas to choose">
                                    @foreach ($authorChoices as $choice)
                                        <li wire:key="studio-plan-author-{{ $choice['id'] }}">
                                            <button type="button" class="rg-admin-studio__choice" wire:click="chooseAuthor({{ $choice['id'] }})" x-on:click="open = false; $refs.trigger.focus()">
                                                <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                                                    @if ($choice['avatar'] !== null)
                                                        <img src="{{ $choice['avatar'] }}" alt="" class="rg-admin-avatar__image" loading="lazy" />
                                                    @else
                                                        {{ $choice['initials'] }}
                                                    @endif
                                                </span>
                                                <span class="rg-admin-table__stack">
                                                    <span class="rg-admin-table__name rg-admin-table__ellipsis">{{ $choice['name'] }}</span>
                                                    <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ '@'.$choice['username'] }}@if ($choice['problem'] === 'restricted') · can’t publish while restricted @endif</span>
                                                </span>
                                                <x-admin.ui.badge :tone="$choice['status']['tone']" :dot="$choice['status']['dot']">{{ $choice['status']['label'] }}</x-admin.ui.badge>
                                                <span class="rg-admin-sr-only">— choose as the author</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @else
                                <p class="rg-admin-hint">{{ trim($authorSearch) !== '' ? 'No persona matches.' : 'No other persona to choose. Personas are made in Content Studio › Personas.' }}</p>
                            @endif
                            @if ($author !== null)
                                <div class="rg-admin-studio__author-panel-footer">
                                    <x-admin.ui.button size="sm" variant="ghost" wire:click="clearAuthor" x-on:click="open = false; $refs.trigger.focus()">Clear author</x-admin.ui.button>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($author !== null && $author['problem'] === 'deleted')
                        <x-admin.ui.inline-notice tone="warning">The author’s account was deleted. Choose another persona, or no author, to save this draft.</x-admin.ui.inline-notice>
                    @elseif ($author !== null && $author['problem'] === 'not_persona')
                        <x-admin.ui.inline-notice tone="warning">This account is no longer a persona. Choose another one, or no author, to save this draft.</x-admin.ui.inline-notice>
                    @elseif ($author !== null && $author['problem'] === 'restricted')
                        <x-admin.ui.inline-notice tone="warning">This persona cannot publish while restricted.</x-admin.ui.inline-notice>
                    @else
                        <p class="rg-admin-hint">The persona this post is published as.</p>
                    @endif
                    @error('authorUserId')
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                    @enderror
                </div>
            </div>
            <div class="rg-admin-studio__final-source">
                <x-admin.ui.text-field
                    label="Source URL"
                    id="rg-admin-studio-source-url"
                    type="url"
                    icon="link"
                    :value="$sourceUrl"
                    :required="false"
                    placeholder="https://example.com/where-it-came-from"
                    hint="Where the post’s content comes from, shown with the post. Separate from the link a photo is imported from."
                    :error="$errors->first('sourceUrl') ?: null"
                    maxlength="{{ $limits['sourceUrl'] }}"
                    autocomplete="off"
                    wire:model="sourceUrl"
                />
            </div>
        </div>
    </x-admin.ui.card>

    <div class="rg-admin-studio__columns">
        <x-admin.ui.card title="Category and tags">
            <div class="rg-admin-studio__stack">
                <div class="rg-admin-field">
                    <x-admin.ui.combobox label="Category" id="rg-admin-studio-category" :options="$categories" :value="$categoryId" search-placeholder="Search categories" empty="No category matches." wire:model="categoryId" />
                    @error('categoryId')
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                    @enderror
                </div>

                <div class="rg-admin-field">
                    <div class="rg-admin-field__head">
                        <span class="rg-admin-label" id="rg-admin-studio-tags-label">Tags</span>
                        <span class="rg-admin-field__counter">{{ count($tags['chosen']) }} / {{ $limits['tags'] }}</span>
                    </div>
                    @if ($tags['chosen'] !== [])
                        <ul class="rg-admin-studio__chips" aria-labelledby="rg-admin-studio-tags-label">
                            @foreach ($tags['chosen'] as $tag)
                                <li class="rg-admin-filter-chip" wire:key="studio-plan-tag-{{ $tag['id'] }}">
                                    {{ $tag['name'] }}
                                    <button type="button" class="rg-admin-filter-chip__remove" aria-label="Remove tag {{ $tag['name'] }}" wire:click="removeTag({{ $tag['id'] }})">
                                        <x-admin.ui.icon name="x" :size="12" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="rg-admin-hint">No tags yet.</p>
                    @endif
                    @if ($tags['full'])
                        <p class="rg-admin-hint">{{ $limits['tags'] }} tags chosen, the most a post carries. Remove one to add another.</p>
                    @else
                        <x-admin.ui.search-field
                            label="Search tags"
                            placeholder="Search tags"
                            name="tagSearch"
                            id="rg-admin-studio-tag-search"
                            :value="$tagSearch"
                            wire:model.live.debounce.300ms="tagSearch"
                        />
                        @if ($tags['offered'] !== [])
                            <ul class="rg-admin-studio__chips" aria-label="Tags to add">
                                @foreach ($tags['offered'] as $tag)
                                    <li wire:key="studio-plan-offered-tag-{{ $tag['id'] }}">
                                        <button type="button" class="rg-admin-studio__chip-add" wire:click="addTag({{ $tag['id'] }})">
                                            <x-admin.ui.icon name="plus" :size="12" />{{ $tag['name'] }}<span class="rg-admin-sr-only"> — add tag</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="rg-admin-hint">{{ trim($tagSearch) !== '' ? 'No tag matches. Tags are created in Content › Tags.' : 'Every tag is chosen.' }}</p>
                        @endif
                    @endif
                    @if ($errors->has('tagIds') || $errors->has('tagIds.*'))
                        <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $errors->first('tagIds') ?: $errors->first('tagIds.*') }}</p>
                    @endif
                </div>
            </div>
        </x-admin.ui.card>
        <x-admin.ui.card title="The author’s answers" description="The answers the author gives to the post’s rating questions, shown as theirs. They are not votes.">
            <div class="rg-admin-studio__stack">
                @forelse ($ratingGroups as $group)
                    <x-admin.ui.combobox
                        :label="$group['label'].($group['inactive'] ? ' · inactive' : '')"
                        id="rg-admin-studio-answer-{{ $group['id'] }}"
                        :options="$group['options']"
                        :value="$authorAnswers[$group['id']] ?? ''"
                        search-placeholder="Search answers"
                        empty="No answer matches."
                        wire:model="authorAnswers.{{ $group['id'] }}"
                        wire:key="studio-plan-answer-{{ $group['id'] }}"
                    />
                @empty
                    <p class="rg-admin-hint">No rating group is active, so there is nothing to answer.</p>
                @endforelse
                @if ($errors->has('authorAnswerOptionIds') || $errors->has('authorAnswerOptionIds.*'))
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $errors->first('authorAnswerOptionIds') ?: $errors->first('authorAnswerOptionIds.*') }}</p>
                @endif
            </div>
        </x-admin.ui.card>
    </div>
</div>
