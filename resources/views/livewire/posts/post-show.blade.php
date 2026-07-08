@section('title', $ogTitle)

@push('meta')
    <link rel="canonical" href="{{ canonical_post_url($post) }}">
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ canonical_post_url($post) }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:secure_url" content="{{ $ogImage }}">
    <meta name="description" content="{{ $ogDescription }}">
    <meta name="twitter:card" content="{{ $ogHasImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
    <meta name="twitter:image:src" content="{{ $ogImage }}">
@endpush

<div
    data-testid="post-show"
    x-data="{
        imageOpen: false,
        shareOpen: false,
        menuOpen: false,
        deleteOpen: false,
        scrollToComments() {
            const comments = this.$refs.postComments;
            if (comments) {
                comments.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }"
    class="mx-auto w-full max-w-[820px] min-w-0 overflow-hidden"
>
    <main class="min-w-0" data-testid="post-show-page">
        <article class="rounded-rgCard border border-rg-border bg-rg-card p-5">
            <section class="flex min-w-0 items-start justify-between gap-3" data-testid="post-show-meta">
                <div class="flex min-w-0 items-start gap-3">
                    @if($post->user?->username)
                        <a href="{{ route('profile.show', $post->user->username) }}" wire:navigate class="shrink-0 rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                            <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user->name" size="lg" />
                        </a>
                    @else
                        <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user?->name ?? 'User'" size="lg" />
                    @endif

                    <div class="min-w-0">
                        @if($post->user?->username)
                            <a href="{{ route('profile.show', $post->user->username) }}" wire:navigate class="truncate text-sm font-semibold text-rg-text hover:underline focus-visible:outline-none block">{{ $post->user->name }}</a>
                        @else
                            <div class="truncate text-sm font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</div>
                        @endif

                        <div class="truncate text-xs text-rg-muted">
                            @if($post->user?->username)
                                {{ '@' . $post->user->username }}
                            @endif
                            @if($post->published_at)
                                {{ $post->user?->username ? ' · ' : '' }}{{ $post->published_at->diffForHumans() }}
                            @endif
                        </div>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if($post->user && $this->canSeeFollowButton)
                        <div data-testid="post-author-follow">
                            <livewire:follows.follow-button :author="$post->user" variant="compact" />
                        </div>
                    @endif
                </div>
            </section>

            <section class="mt-4">
                <h1 class="break-words text-base font-bold leading-snug text-rg-text">{{ $post->title }}</h1>

                @if($post->description)
                    <p class="mt-2 break-words text-[13px] leading-snug text-rg-muted">{{ $post->description }}</p>
                @endif
            </section>

            <div class="mt-4" data-testid="post-show-hero">
            @if($post->public_image_url)
                <button
                    type="button"
                    class="block w-full cursor-zoom-in rounded-rgMedia focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                    x-on:click.stop="imageOpen = true"
                    data-testid="post-show-image-open"
                    aria-label="{{ __('ui.a11y.open_image') }}"
                >
                    <img
                        src="{{ $post->public_image_url }}"
                        alt="{{ $post->title }}"
                        class="aspect-[16/10] w-full rounded-rgMedia object-cover"
                    >
                </button>
            @else
                <x-ui.image-placeholder label="Image preview" ratio="video" />
            @endif
            </div>

            @if($activeRatingGroups->isNotEmpty())
            <section class="mt-5 space-y-5" data-testid="post-show-rating-controls">
                @foreach($activeRatingGroups as $ratingGroup)
                    <div wire:click.stop wire:keydown.stop>
                        <livewire:voting.rating-voting
                            :post="$post"
                            :group-key="$ratingGroup->key"
                            :variant="$ratingGroup->options->count() <= 2 ? 'default' : 'compact'"
                            :key="'post-show-rating-'.$ratingGroup->key.'-'.$post->id"
                        />
                    </div>
                @endforeach
            </section>
            @endif

            <footer class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-rg-border pt-4" data-testid="post-show-footer">
                <div class="flex flex-wrap items-center gap-4">
                    <div data-testid="post-show-voting">
                        <livewire:posts.post-voting
                            :post-id="$post->id"
                            variant="pill"
                            :key="'post-show-voting-'.$post->id"
                        />
                    </div>

                    @if($projectSettings->featureEnabled('show_comments'))
                        <x-ui.action-button icon="comment" x-on:click="scrollToComments()" data-testid="post-show-comments-scroll">
                            {{ $post->comments_count ?? 0 }}
                        </x-ui.action-button>
                    @endif

                    @if($projectSettings->featureEnabled('show_share_buttons'))
                        <x-ui.action-button icon="share" x-on:click="shareOpen = true" data-testid="post-show-share-btn">{{ __('sharing.share') }}</x-ui.action-button>
                    @endif

                    @if($projectSettings->featureEnabled('show_saved_posts'))
                        @auth
                            <livewire:posts.save-post-button
                                :post-id="$post->id"
                                :key="'post-show-save-'.$post->id"
                            />
                        @endauth
                    @endif
                </div>

                @if($canReportPost || $canDeletePost || $canModeratePost)
                    <div class="relative" wire:click.stop wire:keydown.stop>
                        <button
                            type="button"
                            x-on:click="menuOpen = ! menuOpen"
                            class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                            aria-label="{{ __('ui.a11y.post_actions') }}"
                            data-testid="post-show-actions-menu"
                        >
                            <x-ui.icon name="more" class="size-4" />
                        </button>

                        <div
                            x-cloak
                            x-show="menuOpen"
                            x-on:click.outside="menuOpen = false"
                            class="absolute right-0 top-full z-20 mt-2 w-44 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                        >
                            @if($canReportPost)
                                <div>
                                    <livewire:reports.report-modal
                                        reportable-type="post"
                                        :reportable-id="$post->id"
                                        variant="menu"
                                        :key="'post-show-report-'.$post->id"
                                        wire:lazy
                                    />
                                </div>
                            @endif

                            @if($canDeletePost)
                                <button
                                    type="button"
                                    x-on:click="menuOpen = false; deleteOpen = true"
                                    class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                                >
                                    {{ __('ui.post.delete') }}
                                </button>
                            @endif

                            <livewire:moderation.inline-post-moderation
                                :post-id="$post->id"
                                variant="menu"
                                :key="'post-show-moderation-'.$post->id"
                                wire:lazy
                            />
                        </div>
                    </div>
                @endif
            </footer>

            @if($post->tags->isNotEmpty() || $post->source_url)
                <section class="mt-4 flex flex-wrap items-center gap-2">
                    @foreach($post->tags as $tag)
                        <x-ui.badge>{{ $tag->name }}</x-ui.badge>
                    @endforeach

                    @if($post->source_url)
                        <a
                            href="{{ $post->source_url }}"
                            rel="nofollow noopener noreferrer"
                            target="_blank"
                            class="text-xs font-semibold text-rg-accent2 hover:underline"
                        >
                            {{ __('ui.voting.source') }}
                        </a>
                    @endif
                </section>
            @endif

            @if($projectSettings->featureEnabled('show_share_buttons'))
                <x-ui.modal title="{{ __('sharing.share_this_post') }}" state="shareOpen" size="lg">
                    <x-sharing.share-buttons :post="$post" />
                </x-ui.modal>
            @endif

            @if($post->public_image_url)
                <x-ui.modal title="{{ $post->title }}" state="imageOpen" size="fullscreen">
                    <img
                        src="{{ $post->public_image_url }}"
                        alt="{{ $post->title }}"
                        class="max-h-[80vh] w-full rounded-rgMedia object-contain"
                        data-testid="post-fullscreen-image"
                    >
                </x-ui.modal>
            @endif

            @if($canDeletePost)
                <x-ui.modal title="{{ __('ui.post.delete_confirm_title') }}" state="deleteOpen" size="sm">
                    <div class="space-y-4">
                        <p class="text-sm leading-6 text-rg-muted">{{ __('ui.post.delete_confirm_description') }}</p>

                        @if($deleteError)
                            <p class="text-sm text-rg-dangerText">{{ $deleteError }}</p>
                        @endif

                        <div class="flex justify-end gap-2">
                            <x-ui.button type="button" variant="ghost" x-on:click="deleteOpen = false">{{ __('ui.actions.cancel') }}</x-ui.button>
                            <x-ui.button
                                type="button"
                                variant="danger"
                                wire:click="$dispatch('delete-post', { postId: {{ $post->id }} })"
                                wire:loading.attr="disabled"
                                wire:target="deletePost"
                            >
                                {{ __('ui.actions.delete') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.modal>
            @endif
        </article>

        <section class="mt-8 scroll-mt-24" data-testid="post-show-comments" x-ref="postComments">
            <livewire:comments.comments-section
                :post-id="$post->id"
                :show-header="true"
                :key="'comments-'.$post->id"
            />
        </section>
    </main>
</div>
