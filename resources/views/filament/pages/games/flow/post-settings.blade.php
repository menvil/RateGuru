{{--
    Which post a step sends the visitor to — one chosen from the list of
    posts with their pictures, or linked by its address, #id or words of its
    title or author, looked up as they are typed; the post of their last
    pick; a fresh one — and what to ask there: the vote and the site's
    rating groups.
--}}
@php
    $settings = $step['settings'];
    $asked = array_values(array_filter(explode(',', (string) ($settings['ask_groups'] ?? ''))));
    $askVote = (bool) ($settings['ask_vote'] ?? true);
@endphp
@include('filament.pages.games.flow.choice', [
    'label' => 'Which post',
    'options' => ['chosen' => 'A post you choose', 'last_pick' => 'The post they picked in the last round', 'fresh' => 'A fresh post they haven’t rated'],
    'value' => $settings['post_target'] ?? 'last_pick',
    'model' => $path.'.settings.post_target',
])

@if (($settings['post_target'] ?? '') === 'chosen')
    <div class="rg-admin-field">
        <span class="rg-admin-label">Post</span>
        <x-admin.ui.button icon="images" wire:click="openPicker" data-testid="game-flow-choose-post">{{ $linkedPost === null ? 'Choose a post' : 'Choose another post' }}</x-admin.ui.button>
        <label class="rg-admin-games__note" for="rg-admin-step-post-link">or paste its link or #id, or type words of its title or author</label>
        <div class="rg-admin-games__post-link">
            <x-admin.ui.icon name="link" :size="14" class="rg-admin-games__post-link-icon" />
            <input id="rg-admin-step-post-link" type="text" class="rg-admin-input rg-admin-games__post-link-input" placeholder="https://…/posts/123, #123 or words of the title" autocomplete="off" wire:model.live.debounce.400ms="postLink" wire:keydown.enter.prevent="linkPost" />
        </div>
        @if ($postLinkError !== '')
            <span class="rg-admin-games__note rg-admin-games__note--danger">{{ $postLinkError }}</span>
        @endif
        @if ($postMatches !== [])
            <ul class="rg-admin-games__post-matches" data-testid="game-flow-post-matches">
                @foreach ($postMatches as $match)
                    <li>
                        <button type="button" class="rg-admin-games__post-match" wire:click="choosePost({{ $match['id'] }})">
                            <span class="rg-admin-games__post-match-title">{{ $match['title'] }}</span>
                            <span class="rg-admin-games__note">#{{ $match['id'] }} · {{ '@'.$match['author'] }} · {{ $match['votes'] }} {{ \Illuminate\Support\Str::plural('vote', $match['votes']) }}{{ $match['published'] ? '' : ' · hidden' }}</span>
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @if ($linkedPost !== null)
        <div class="rg-admin-games__linked-post" data-testid="game-flow-linked-post">
            <span class="rg-admin-games__linked-post-sign" aria-hidden="true"><x-admin.ui.icon name="newspaper" :size="16" /></span>
            <span class="rg-admin-games__linked-post-text">
                <a href="{{ $linkedPost['url'] }}" target="_blank" rel="noopener" class="rg-admin-games__linked-post-title">{{ $linkedPost['title'] }}</a>
                <span class="rg-admin-games__note">#{{ $linkedPost['id'] }} · by {{ $linkedPost['author'] }} · {{ $linkedPost['votes'] }} {{ \Illuminate\Support\Str::plural('vote', $linkedPost['votes']) }}{{ $linkedPost['up'] === null ? '' : ' · '.$linkedPost['up'].'% up' }}</span>
            </span>
            <x-admin.ui.badge :tone="$linkedPost['published'] ? 'success' : 'danger'">{{ $linkedPost['published'] ? 'Published' : 'Hidden' }}</x-admin.ui.badge>
            <x-admin.ui.icon-button icon="x" label="Remove the link" variant="ghost" size="sm" wire:click="unlinkPost" />
        </div>
        @unless ($linkedPost['published'])
            <x-admin.ui.inline-notice tone="warning">This post is hidden, so visitors can’t open it. Until it’s published again they go to the feed instead and the step doesn’t count.</x-admin.ui.inline-notice>
        @endunless
    @endif
@endif

<div class="rg-admin-field">
    <span class="rg-admin-label" id="rg-admin-step-card-label">On the post's card</span>
    <div class="rg-admin-games__asks" role="group" aria-labelledby="rg-admin-step-card-label">
        @foreach (['post_author' => 'Author', 'post_likes' => 'Upvotes', 'post_votes' => 'Votes', 'post_comments' => 'Comments'] as $key => $label)
            @php($on = (bool) ($settings[$key] ?? true))
            <button type="button" @class(['rg-admin-games__ask', 'rg-admin-games__ask--on' => $on]) aria-pressed="{{ $on ? 'true' : 'false' }}" wire:click="$set('{{ $path }}.settings.{{ $key }}', {{ $on ? 'false' : 'true' }})">
                @if ($on)<x-admin.ui.icon name="check" :size="12" />@endif{{ $label }}
            </button>
        @endforeach
    </div>
    <span class="rg-admin-games__note">The post shows with its picture and title; comments only once it has some.</span>
</div>

<div class="rg-admin-field">
    <span class="rg-admin-label" id="rg-admin-step-ask-label">Ask on the post</span>
    <div class="rg-admin-games__asks" role="group" aria-labelledby="rg-admin-step-ask-label">
        <button type="button" @class(['rg-admin-games__ask', 'rg-admin-games__ask--on' => $askVote]) aria-pressed="{{ $askVote ? 'true' : 'false' }}" wire:click="$set('{{ $path }}.settings.ask_vote', {{ $askVote ? 'false' : 'true' }})">
            @if ($askVote)<x-admin.ui.icon name="check" :size="12" />@endif Up or down vote
        </button>
        @foreach ($ratingGroups as $group)
            @php($on = in_array($group['key'], $asked, true))
            <button type="button" @class(['rg-admin-games__ask', 'rg-admin-games__ask--on' => $on]) aria-pressed="{{ $on ? 'true' : 'false' }}" wire:click="toggleAsk('{{ $group['key'] }}')">
                @if ($on)<x-admin.ui.icon name="check" :size="12" />@endif {{ $group['label'] }}
            </button>
        @endforeach
    </div>
    <span class="rg-admin-games__note">
        @if (! $askVote && $asked === [])
            Nothing highlighted: the post just opens. Opening it counts as Opened the post.
        @else
            The post opens with these highlighted. Rating groups come from Content → Rating groups. A vote within 30 minutes counts as Voted on the post, a rating group as Rated the post.
        @endif
    </span>
</div>
