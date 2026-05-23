<div data-testid="post-drawer">
    <div wire:loading data-testid="post-drawer-loading" class="space-y-4">
        <x-ui.skeleton shape="block" height="16rem" />
        <x-ui.skeleton shape="line" width="70%" />
        <x-ui.skeleton shape="line" width="45%" />
    </div>

    <div wire:loading.remove>
    @if($post)
        @if($post->public_image_url)
            <img
                src="{{ $post->public_image_url }}"
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

        <div class="mt-4 grid grid-cols-3 gap-2">
            <div class="rounded-rgCard border border-rg-border bg-rg-card2 p-3 text-center">
                <div class="text-xs text-rg-muted">Score</div>
                <div class="mt-1 text-base font-bold text-rg-text">{{ $post->score }}</div>
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

        <div data-testid="post-drawer-voting" class="mt-4">
            <livewire:posts.post-voting
                :post-id="$post->id"
                :key="'post-drawer-voting-'.$post->id"
            />
        </div>

        @if($showSharePanel)
            <div class="mt-6" data-testid="post-drawer-share-panel">
                <x-share.post-share-panel :post="$post" />
            </div>
        @endif

        <section class="mt-6" data-testid="post-drawer-origin-voting">
            <h3 class="text-sm font-semibold text-rg-text">Homemade or Restaurant?</h3>

            <div class="mt-2">
                <livewire:posts.origin-voting
                    :post-id="$post->id"
                    :key="'post-drawer-origin-voting-'.$post->id"
                />
            </div>
        </section>

        <div class="mt-6 flex justify-end" data-testid="post-drawer-report">
            <livewire:reports.report-modal
                reportable-type="post"
                :reportable-id="$post->id"
                :key="'post-drawer-report-'.$post->id"
            />
        </div>

        <section class="mt-6" data-testid="post-drawer-cuisine-voting">
            <h3 class="text-sm font-semibold text-rg-text">What cuisine is it?</h3>

            <div class="mt-2">
                <livewire:posts.cuisine-voting
                    :post-id="$post->id"
                    :key="'post-drawer-cuisine-voting-'.$post->id"
                />
            </div>
        </section>

        <section class="mt-6" data-testid="drawer-comments-slot">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-sm font-semibold text-rg-text">Comments</h3>
                <span class="text-xs text-rg-muted">{{ $post->comments_count ?? 0 }}</span>
            </div>

            <livewire:comments.comments-section :post-id="$post->id" :show-header="false" :key="'drawer-comments-'.$post->id" />
        </section>

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
        <x-ui.error-message
            title="Post not found"
            message="This post is unavailable or no longer public."
        />
    @else
        <x-ui.empty-state
            title="Select a post"
            description="Post details will appear here."
        />
    @endif
    </div>
</div>
