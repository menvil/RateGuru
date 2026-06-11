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

                @if($post->user && $this->canSeeFollowButton)
                    <div class="shrink-0" data-testid="post-author-follow">
                        <livewire:follows.follow-button :author="$post->user" variant="compact" />
                    </div>
                @endif
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
                    aria-label="Open image fullscreen"
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
                <div class="flex flex-wrap items-center gap-2">
                    <livewire:posts.post-voting
                        :post-id="$post->id"
                        variant="pill"
                        :key="'post-show-voting-'.$post->id"
                    />
                </div>

                <div class="flex flex-wrap items-center gap-4" x-data="{ shareOpen: false }">
                    @if($projectSettings->featureEnabled('show_comments'))
                        <x-ui.action-button icon="comment" x-on:click="scrollToComments()" data-testid="post-show-comments-scroll">
                            {{ $post->comments_count ?? 0 }}
                        </x-ui.action-button>
                    @endif

                    @if($projectSettings->featureEnabled('show_share_buttons'))
                        <x-ui.action-button icon="share" x-on:click="shareOpen = true" data-testid="post-show-share-btn">{{ __('sharing.share') }}</x-ui.action-button>
                        <x-ui.modal title="{{ __('sharing.share_this_post') }}" state="shareOpen" size="lg">
                            <x-sharing.share-buttons :post="$post" />
                        </x-ui.modal>
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
