{{--
    Step 2 · Post. AI text suggestions first (StudioPlanTextSuggestionsCard),
    written from the photo's analysis, drawn afresh for every photo; then what
    the post will be: its final title and description — a suggestion used
    lands here, unsaved, and stays editable — and its source address; the
    Persona it is published as, chosen from living Personas found on the
    server; its category and tags; and the author's own answers to the rating
    questions. Nothing here is a vote.
--}}
<livewire:admin.studio-plan-text-suggestions-card
    :plan-id="$analysisPlanId"
    :image-asset-id="$analysisImageId"
    :wire:key="'studio-plan-text-suggestions-'.$analysisPlanId.'-'.($analysisImageId ?? 'none')"
/>

<div class="rg-admin-studio__columns">
    <div class="rg-admin-studio__column">
        <x-admin.ui.card title="Final texts" description="The title and description the post is published with. Pick a suggestion above or write your own; both stay editable.">
            <div class="rg-admin-studio__stack">
                <x-admin.ui.textarea
                    label="Final title"
                    id="rg-admin-studio-title"
                    :value="$postTitle"
                    :rows="2"
                    :limit="$limits['title']"
                    :required="false"
                    placeholder="What the post is called"
                    hint="{{ $limits['titleMin'] }} to {{ $limits['title'] }} characters. A draft may leave it empty for now."
                    :error="$errors->first('title') ?: null"
                    wire:model="postTitle"
                />
                <x-admin.ui.textarea
                    label="Final description"
                    id="rg-admin-studio-description"
                    :value="$postDescription"
                    :rows="5"
                    :limit="$limits['description']"
                    :required="false"
                    placeholder="A few lines under the photo"
                    :error="$errors->first('description') ?: null"
                    wire:model="postDescription"
                />
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
        </x-admin.ui.card>

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
    </div>

    <div class="rg-admin-studio__column">
        <x-admin.ui.card title="Post author" description="The persona this post is published as.">
            <div class="rg-admin-studio__stack">
                @if ($author !== null)
                    <div class="rg-admin-studio__author">
                        <span class="rg-admin-avatar rg-admin-avatar--md" aria-hidden="true">
                            @if ($author['avatar'] !== null)
                                <img src="{{ $author['avatar'] }}" alt="" class="rg-admin-avatar__image" />
                            @elseif ($author['initials'] !== '')
                                {{ $author['initials'] }}
                            @else
                                <x-admin.ui.icon name="user" :size="14" />
                            @endif
                        </span>
                        <span class="rg-admin-table__stack">
                            <span class="rg-admin-table__name">{{ $author['name'] }}</span>
                            @if ($author['username'] !== null)
                                <span class="rg-admin-table__meta rg-admin-table__meta--small">{{ '@'.$author['username'] }}</span>
                            @endif
                        </span>
                        @if ($author['status'] !== null)
                            <x-admin.ui.badge :tone="$author['status']['tone']" :dot="$author['status']['dot']">{{ $author['status']['label'] }}</x-admin.ui.badge>
                        @endif
                        <x-admin.ui.button size="sm" variant="ghost" class="rg-admin-studio__author-clear" wire:click="clearAuthor">Clear author</x-admin.ui.button>
                    </div>
                    @if ($author['problem'] === 'deleted')
                        <x-admin.ui.inline-notice tone="warning">The author’s account was deleted. Choose another persona, or no author, to save this draft.</x-admin.ui.inline-notice>
                    @elseif ($author['problem'] === 'not_persona')
                        <x-admin.ui.inline-notice tone="warning">This account is no longer a persona. Choose another one, or no author, to save this draft.</x-admin.ui.inline-notice>
                    @elseif ($author['problem'] === 'restricted')
                        <x-admin.ui.inline-notice tone="warning">This persona cannot publish while restricted.</x-admin.ui.inline-notice>
                    @endif
                @else
                    <p class="rg-admin-studio__empty">No author selected</p>
                @endif
                @error('authorUserId')
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                @enderror

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
                                <button type="button" class="rg-admin-studio__choice" wire:click="chooseAuthor({{ $choice['id'] }})">
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
