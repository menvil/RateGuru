@section('title', $ogTitle)

@push('meta')
    <meta property="og:type" content="article">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:url" content="{{ canonical_post_url($post) }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta name="description" content="{{ $ogDescription }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    <meta name="twitter:image" content="{{ $ogImage }}">
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
    <span data-testid="post-show-page" class="sr-only"></span>
    <main class="min-w-0">
        <article class="rounded-rgCard border border-rg-border bg-rg-card p-5">
            <section class="flex min-w-0 items-start gap-3" data-testid="post-show-meta">
                <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user?->name ?? 'User'" size="lg" />

                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</div>

                    <div class="truncate text-xs text-rg-muted">
                        @if($post->user?->username)
                            {{ '@' . $post->user->username }}
                        @endif
                        @if($post->published_at)
                            {{ $post->user?->username ? ' · ' : '' }}{{ $post->published_at->diffForHumans() }}
                        @endif
                    </div>
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
                            :key="'post-show-rating-'.$ratingGroup->key.'-'.$post->id"
                        />
                    </div>
                @endforeach
            </section>
            @endif

            <section class="mt-5 border-t border-rg-border pt-4" data-testid="post-show-voting">
                <livewire:posts.post-voting
                    :post-id="$post->id"
                    :key="'post-show-voting-'.$post->id"
                />
            </section>

            <section class="mt-4 grid grid-cols-3 gap-2" aria-label="Voting summary" data-testid="post-show-vote-summary">
                <x-ui.card class="text-center">
                    <div class="text-xs text-rg-muted">Score</div>
                    <div class="mt-1 text-base font-bold text-rg-text">{{ $post->score }}</div>
                </x-ui.card>

                <x-ui.card class="text-center">
                    <div class="text-xs text-rg-muted">Source A</div>
                    <div class="mt-1 text-base font-bold text-rg-text">{{ $post->homemade_votes_count ?? 0 }}</div>
                </x-ui.card>

                <x-ui.card class="text-center">
                    <div class="text-xs text-rg-muted">Source B</div>
                    <div class="mt-1 text-base font-bold text-rg-text">{{ $post->restaurant_votes_count ?? 0 }}</div>
                </x-ui.card>
            </section>

            <div class="mt-3">
                <x-ui.action-button icon="comment" x-on:click="scrollToComments()" data-testid="post-show-comments-scroll">
                    {{ $post->comments_count ?? 0 }}
                </x-ui.action-button>
            </div>

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
