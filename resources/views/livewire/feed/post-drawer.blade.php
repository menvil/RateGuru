<div data-testid="post-drawer">
    @if($post)
        @if($post->image_url)
            <img
                src="{{ $post->image_url }}"
                alt="{{ $post->title }}"
                class="aspect-[4/3] w-full rounded-rgCard object-cover"
            >
        @else
            <x-ui.image-placeholder label="Image preview" ratio="video" />
        @endif

        <section class="mt-4">
            <h2 class="text-lg font-bold text-rg-text">{{ $post->title }}</h2>

            @if($post->description)
                <p class="mt-2 text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
            @endif
        </section>

        @php $score = ($post->upvotes_count ?? 0) - ($post->downvotes_count ?? 0); @endphp
        <div class="mt-4 grid grid-cols-3 gap-2">
            <div class="rounded-rgCard border border-rg-border bg-rg-card2 p-3 text-center">
                <div class="text-xs text-rg-muted">Score</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $score }}</div>
            </div>
            <div class="rounded-rgCard border border-rg-border bg-rg-card2 p-3 text-center">
                <div class="text-xs text-rg-muted">Homemade</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->homemade_votes_count ?? 0 }}</div>
            </div>
            <div class="rounded-rgCard border border-rg-border bg-rg-card2 p-3 text-center">
                <div class="text-xs text-rg-muted">Restaurant</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->restaurant_votes_count ?? 0 }}</div>
            </div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            <x-ui.avatar :name="$post->user?->name ?? 'User'" size="md" />

            <div>
                <div class="text-sm font-semibold text-rg-text">{{ $post->user?->name ?? 'Unknown user' }}</div>

                @if($post->user?->username)
                    <div class="text-xs text-rg-muted">{{ '@' . $post->user->username }}</div>
                @endif

                @if($post->published_at)
                    <div class="text-xs text-rg-muted">{{ $post->published_at->diffForHumans() }}</div>
                @endif
            </div>
        </div>
    @elseif($postId)
        Post not found
    @else
        Select a post
    @endif
</div>
