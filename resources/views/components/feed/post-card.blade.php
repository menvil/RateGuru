@props([
    'post',
    'selected' => false,
])

<x-ui.card
    variant="{{ $selected ? 'selected-post' : 'post' }}"
    data-testid="post-card"
    data-post-id="{{ $post->id }}"
    role="button"
    tabindex="0"
    wire:click="$dispatch('select-post', { postId: {{ $post->id }} })"
    wire:keydown.enter="$dispatch('select-post', { postId: {{ $post->id }} })"
    wire:keydown.space.prevent="$dispatch('select-post', { postId: {{ $post->id }} })"
    class="grid cursor-pointer grid-cols-[32px_minmax(0,1fr)] gap-3 overflow-hidden transition-colors hover:border-rg-border2 hover:bg-rg-cardHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
>
    <div data-testid="post-card-voting" class="w-8 pt-1.5" wire:click.stop wire:keydown.stop>
        @if($post->exists)
            <livewire:posts.post-voting
                :post-id="$post->id"
                variant="rail"
                :key="'post-card-vote-rail-'.$post->id"
            />
        @else
            <x-ui.vote-rail :score="$post->score" active="none" />
        @endif
    </div>

    <div class="min-w-0">
        <div class="flex min-w-0 items-start gap-2">
            <x-ui.avatar :name="$post->user?->name ?? 'User'" size="md" />
            <div class="min-w-0 flex-1">
                <span class="block truncate text-[13px] font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</span>
                <span class="block truncate text-xs text-rg-muted">
                    @if($post->user?->username)
                        {{ '@' . $post->user->username }}
                    @endif
                    @if($post->published_at)
                        {{ $post->user?->username ? ' · ' : '' }}{{ $post->published_at->diffForHumans() }}
                    @endif
                </span>
            </div>

            @if($post->exists)
                @php
                    $postCardUser = auth()->user();
                    $canReportPost = auth()->id() !== $post->user_id;
                    $canDeletePost = $postCardUser !== null && ((int) $postCardUser->id === (int) $post->user_id || $postCardUser->isAdmin() || $postCardUser->isModerator());
                @endphp

                <div class="relative -mt-1 ml-auto" x-data="{ postMenuOpen: false, deleteOpen: false }" wire:click.stop wire:keydown.stop>
                    <button
                        type="button"
                        x-on:click="postMenuOpen = ! postMenuOpen"
                        aria-label="Post actions"
                        class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    >
                        <x-ui.icon name="more" class="size-4" />
                    </button>

                    <div
                        x-cloak
                        x-show="postMenuOpen"
                        x-on:click.outside="postMenuOpen = false"
                        class="absolute right-0 top-full z-20 mt-2 w-44 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                    >
                        @if($canReportPost)
                            <div data-testid="post-card-report" class="rounded-rgSm px-3 py-1.5 transition hover:bg-rg-card">
                                <livewire:reports.report-modal
                                    reportable-type="post"
                                    :reportable-id="$post->id"
                                    :key="'post-card-report-'.$post->id"
                                />
                            </div>
                        @endif

                        @if($canDeletePost)
                            <button
                                type="button"
                                data-testid="post-card-delete"
                                x-on:click="postMenuOpen = false; deleteOpen = true"
                                class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                            >
                                Delete post
                            </button>
                        @endif

                        <livewire:moderation.inline-post-moderation
                            :post-id="$post->id"
                            variant="menu"
                            :key="'post-card-moderation-'.$post->id"
                        />

                        @if(! $canReportPost && ! $canDeletePost)
                            <span class="block px-3 py-2 text-sm text-rg-muted">No actions</span>
                        @endif
                    </div>

                    <x-ui.modal title="Delete post?" state="deleteOpen" size="sm">
                        <div class="space-y-4">
                            <p class="text-sm leading-6 text-rg-muted">This will remove the post from public feeds.</p>

                            <div class="flex justify-end gap-2">
                                <x-ui.button type="button" variant="ghost" x-on:click="deleteOpen = false">Cancel</x-ui.button>
                                <x-ui.button
                                    type="button"
                                    variant="danger"
                                    wire:click="$dispatch('delete-post', { postId: {{ $post->id }} })"
                                    x-on:click="deleteOpen = false"
                                >
                                    Delete
                                </x-ui.button>
                            </div>
                        </div>
                    </x-ui.modal>
                </div>
            @endif
        </div>

        <h3 class="mt-3 break-words text-base font-bold leading-snug text-rg-text">{{ $post->title }}</h3>

        @if($post->public_image_url)
            <img
                src="{{ $post->public_image_url }}"
                alt="{{ $post->title }}"
                class="mt-3 aspect-[16/10] w-full rounded-rgMedia object-cover"
            >
        @else
            <div class="mt-3">
                <x-ui.image-placeholder label="Food image" ratio="feed" />
            </div>
        @endif

        @if($post->truncated_description)
            <p class="mt-3 break-words text-[13px] leading-snug text-rg-muted">{{ $post->truncated_description }}</p>
        @endif

        <div class="mt-3 space-y-3" wire:click.stop wire:keydown.stop>
            @if($post->exists)
                <div data-testid="post-card-origin-voting">
                    <p class="mb-2 text-[13px] font-semibold text-rg-text2">What do you think?</p>
                    <livewire:posts.origin-voting
                        :post-id="$post->id"
                        :key="'post-card-origin-voting-'.$post->id"
                    />
                </div>

                <div data-testid="post-card-cuisine-voting">
                    <p class="mb-2 text-[13px] font-semibold text-rg-text2">Cuisine guess:</p>
                    <livewire:posts.cuisine-voting
                        :post-id="$post->id"
                        :key="'post-card-cuisine-voting-'.$post->id"
                    />
                </div>
            @else
                <div class="flex flex-wrap gap-2">
                    <x-ui.badge>Homemade {{ $post->homemade_votes_count ?? 0 }}</x-ui.badge>
                    <x-ui.badge>Restaurant {{ $post->restaurant_votes_count ?? 0 }}</x-ui.badge>
                </div>
            @endif
        </div>

        <footer class="mt-3.5 flex flex-wrap items-center gap-4 border-t border-rg-border pt-2.5">
            <x-ui.action-button icon="comment">{{ $post->comments_count ?? 0 }}</x-ui.action-button>
            <span class="sr-only">{{ $post->comments_count ?? 0 }} comments</span>
            <x-ui.action-button icon="share">Share</x-ui.action-button>
            @if($post->exists)
                <livewire:posts.save-post-button
                    :post-id="$post->id"
                    :key="'post-card-save-'.$post->id"
                />
            @else
                <x-ui.action-button icon="bookmark">Save</x-ui.action-button>
            @endif

        </footer>
    </div>
</x-ui.card>
