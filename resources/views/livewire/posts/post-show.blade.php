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

<div data-testid="post-show" x-data="{ shareOpen: false }" class="mx-auto grid w-full max-w-6xl gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
    <main class="min-w-0">
        <div data-testid="post-show-hero">
            @if($post->public_image_url)
                <img
                    src="{{ $post->public_image_url }}"
                    alt="{{ $post->title }}"
                    class="aspect-[16/10] w-full rounded-rgCard object-cover"
                >
            @else
                <x-ui.image-placeholder label="Image preview" ratio="video" />
            @endif
        </div>

        <section class="mt-6">
            <h1 class="break-words text-2xl font-bold text-rg-text sm:text-3xl">{{ $post->title }}</h1>

            @if($post->description)
                <p class="mt-3 break-words text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
            @endif
        </section>

        <section class="mt-6 flex flex-wrap items-center gap-4" data-testid="post-show-meta">
            <div class="flex min-w-0 items-center gap-3">
                <x-ui.avatar :src="$post->user?->avatar_url" :name="$post->user?->name ?? 'User'" size="lg" />

                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</div>

                    @if($post->user?->username)
                        <div class="truncate text-xs text-rg-muted">{{ '@' . $post->user->username }}</div>
                    @endif

                    @if($post->published_at)
                        <div class="text-xs text-rg-muted">{{ $post->published_at->diffForHumans() }}</div>
                    @endif
                </div>
            </div>

            @if($post->tags->isNotEmpty())
                <div class="flex flex-wrap items-center gap-2">
                    @foreach($post->tags as $tag)
                        <x-ui.badge>{{ $tag->name }}</x-ui.badge>
                    @endforeach
                </div>
            @endif

            @if($post->source_url)
                <a
                    href="{{ $post->source_url }}"
                    rel="nofollow noopener noreferrer"
                    target="_blank"
                    class="text-xs font-semibold text-rg-accent2 hover:underline"
                >
                    Source
                </a>
            @endif
        </section>

        <section class="mt-8" data-testid="post-show-comments">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-base font-semibold text-rg-text">Comments</h2>
                <span class="text-xs text-rg-muted">{{ $post->comments_count ?? 0 }}</span>
            </div>

            <livewire:comments.comments-section
                :post-id="$post->id"
                :show-header="false"
                :key="'comments-'.$post->id"
            />
        </section>
    </main>

    <aside class="min-w-0 space-y-6 lg:sticky lg:top-24 lg:self-start" data-testid="post-show-side-panel">
        <div class="flex items-center justify-end">
            <livewire:posts.save-post-button
                :post-id="$post->id"
                :key="'post-show-save-'.$post->id"
            />
        </div>

        <div data-testid="post-show-voting">
            <livewire:posts.post-voting
                :post-id="$post->id"
                :key="'post-show-voting-'.$post->id"
            />
        </div>

        <section class="grid grid-cols-3 gap-2" aria-label="Voting summary" data-testid="post-show-vote-summary">
            <x-ui.card class="text-center">
                <div class="text-xs text-rg-muted">Score</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->score }}</div>
            </x-ui.card>

            <x-ui.card class="text-center">
                <div class="text-xs text-rg-muted">Homemade</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->homemade_votes_count ?? 0 }}</div>
            </x-ui.card>

            <x-ui.card class="text-center">
                <div class="text-xs text-rg-muted">Restaurant</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->restaurant_votes_count ?? 0 }}</div>
            </x-ui.card>
        </section>

        @if($showSharePanel)
            <x-ui.action-button icon="share" x-on:click="shareOpen = true" data-testid="post-show-share-trigger">
                Share
            </x-ui.action-button>

            <x-ui.modal title="Share post" state="shareOpen" size="lg">
                <x-share.post-share-panel :post="$post" />
            </x-ui.modal>
        @endif

        <section data-testid="post-show-related">
            <h2 class="mb-3 text-base font-semibold text-rg-text">Related posts</h2>

            <x-ui.empty-state
                title="Related dishes will appear here"
                description="Related post recommendations will be added later."
            />
        </section>
    </aside>
</div>
