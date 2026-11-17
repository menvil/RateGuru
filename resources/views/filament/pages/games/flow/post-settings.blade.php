{{--
    Which post a step sends the visitor to — one linked by its address, #id or
    words of its title; the post of their last pick; a fresh one — and what to
    ask there: the vote, the site's rating groups, and whether the post opens
    by itself.
--}}
@php
    $settings = $step['settings'];
    $asked = array_values(array_filter(explode(',', (string) ($settings['ask_groups'] ?? ''))));
    $targets = [
        'last_pick' => ['label' => 'Their last pick', 'help' => 'The post of the option they picked in the last round; a fresh post when it has none.'],
        'chosen' => ['label' => 'A post you link', 'help' => 'The same post for every visitor.'],
        'fresh' => ['label' => 'A fresh post', 'help' => 'The newest post outside the game they haven’t voted on.'],
    ];
@endphp
<x-admin.ui.radio-cards label="Which post" :options="$targets" :value="$settings['post_target'] ?? 'last_pick'" wire:model.live="{{ $path }}.settings.post_target" />

@if (($settings['post_target'] ?? '') === 'chosen')
    @if ($linkedPost !== null)
        <div class="rg-admin-games__linked-post" data-testid="game-flow-linked-post">
            <a href="{{ $linkedPost['url'] }}" target="_blank" rel="noopener" class="rg-admin-games__linked-post-title">#{{ $linkedPost['id'] }} · {{ $linkedPost['title'] }}</a>
            <x-admin.ui.badge :tone="$linkedPost['published'] ? 'success' : 'danger'">{{ $linkedPost['published'] ? 'Published' : 'Hidden' }}</x-admin.ui.badge>
            @unless ($linkedPost['published'])
                <p class="rg-admin-games__note">This post is hidden, so visitors can’t open it. Link another post, or publish it again.</p>
            @endunless
            <x-admin.ui.button size="sm" icon="x" wire:click="unlinkPost">Remove link</x-admin.ui.button>
        </div>
    @else
        <form class="rg-admin-field" wire:submit="linkPost">
            <x-admin.ui.text-field label="Post link" id="rg-admin-step-post-link" :value="$postLink" hint="Paste the post’s address or #id, or type words from its title." :error="$postLinkError !== '' ? $postLinkError : null" wire:model="postLink" />
            <x-admin.ui.button size="sm" type="submit" icon="link">Link post</x-admin.ui.button>
        </form>
        @if ($postMatches !== [])
            <ul class="rg-admin-games__post-matches" data-testid="game-flow-post-matches">
                @foreach ($postMatches as $match)
                    <li><button type="button" class="rg-admin-games__post-match" wire:click="choosePost({{ $match['id'] }})">#{{ $match['id'] }} · {{ $match['title'] }} <span class="rg-admin-games__note">{{ '@'.$match['author'] }}</span></button></li>
                @endforeach
            </ul>
        @endif
    @endif
@endif

<fieldset class="rg-admin-field">
    <legend class="rg-admin-label">Ask on the post</legend>
    <x-admin.ui.checkbox label="Up or down vote" id="rg-admin-step-ask-vote" :checked="(bool) ($settings['ask_vote'] ?? true)" wire:model.live="{{ $path }}.settings.ask_vote" />
    @foreach ($ratingGroups as $group)
        <x-admin.ui.checkbox :label="$group['label']" :id="'rg-admin-step-ask-'.$group['key']" :checked="in_array($group['key'], $asked, true)" wire:click="toggleAsk('{{ $group['key'] }}')" />
    @endforeach
    <span class="rg-admin-games__note">Highlighted on the post. Voting there asks for an account.</span>
</fieldset>
<x-admin.ui.switch label="Open the post by itself" id="rg-admin-step-auto-open" :checked="(bool) ($settings['auto_open'] ?? false)" on-hint="After 3 seconds, once." off-hint="The visitor taps the button." wire:model.live="{{ $path }}.settings.auto_open" />
