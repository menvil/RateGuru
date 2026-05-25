<div data-testid="post-drawer">
    <div wire:loading data-testid="post-drawer-loading" class="space-y-4 transition-opacity duration-200">
        <x-ui.skeleton shape="block" height="16rem" />
        <x-ui.skeleton shape="line" width="70%" />
        <x-ui.skeleton shape="line" width="45%" />
    </div>

    <div wire:loading.remove class="transition-opacity duration-200">
    @if($post)
        <article class="relative rounded-rgCard border border-rg-border bg-rg-card px-5 pb-3.5 pt-5">
            <button
                type="button"
                aria-label="Close"
                data-testid="post-detail-close"
                wire:click="$dispatch('clear-selected-post')"
                class="absolute right-3.5 top-3.5 grid size-8 cursor-pointer place-items-center rounded-rgSm border border-rg-border2 bg-rg-card2 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
            >
                <x-ui.icon name="x" class="size-4" />
            </button>

            <p class="text-xs font-medium text-rg-muted">
                Posted by {{ $post->user?->username ? '@'.$post->user->username : ($post->user?->name ?? 'Unknown user') }}
                @if($post->published_at)
                    · {{ $post->published_at->diffForHumans() }}
                @endif
            </p>
            <h2 class="mt-2 pr-10 text-[22px] font-bold tracking-normal text-rg-text">{{ $post->title }}</h2>

            <div class="mt-4">
                @if($post->public_image_url)
                    <img
                        src="{{ $post->public_image_url }}"
                        alt="{{ $post->title }}"
                        class="aspect-[4/3] w-full rounded-rgMedia object-cover"
                    >
                @else
                    <x-ui.image-placeholder label="Image preview" ratio="detail" />
                @endif
            </div>

            @if($post->description)
                <p class="mt-3 text-sm leading-relaxed text-rg-muted">{{ $post->description }}</p>
            @endif

            <footer class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-rg-border pt-3">
                <div class="flex items-center gap-4">
                    <div data-testid="post-drawer-voting" wire:click.stop wire:keydown.stop>
                        <livewire:posts.post-voting
                            :post-id="$post->id"
                            variant="rail"
                            :key="'post-detail-vote-rail-'.$post->id"
                        />
                    </div>
                    <x-ui.action-button icon="comment">{{ $post->comments_count ?? 0 }}</x-ui.action-button>
                    <x-ui.action-button icon="share">Share</x-ui.action-button>
                    <x-ui.action-button icon="bookmark">Save</x-ui.action-button>
                </div>
                <button type="button" class="rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent">
                    <x-ui.icon name="more" class="size-4" />
                </button>
            </footer>
        </article>

        <section data-testid="post-detail-results" class="mt-4 grid gap-6 rounded-rgCard border border-rg-border bg-rg-card p-5 sm:grid-cols-2">
            <div>
                <div class="mb-3 flex items-baseline gap-2">
                    <h3 class="text-base font-bold text-rg-text">Results</h3>
                    <span class="text-xs text-rg-muted">Score {{ $post->score }}</span>
                </div>

                @php
                    $originTotal = max(1, (int) ($post->homemade_votes_count ?? 0) + (int) ($post->restaurant_votes_count ?? 0));
                    $homemadePct = (int) round(((int) ($post->homemade_votes_count ?? 0) / $originTotal) * 100);
                    $restaurantPct = 100 - $homemadePct;
                @endphp

                <div class="mt-4 space-y-3">
                    <div>
                        <div class="mb-1 flex justify-between text-xs font-semibold text-rg-text2">
                            <span>Homemade</span><span>{{ $homemadePct }}%</span>
                        </div>
                        <div class="h-2 rounded-rgPill bg-rg-card2"><div class="h-2 rounded-rgPill bg-rg-good" style="width: {{ $homemadePct }}%"></div></div>
                    </div>
                    <div>
                        <div class="mb-1 flex justify-between text-xs font-semibold text-rg-text2">
                            <span>Restaurant</span><span>{{ $restaurantPct }}%</span>
                        </div>
                        <div class="h-2 rounded-rgPill bg-rg-card2"><div class="h-2 rounded-rgPill bg-rg-accent" style="width: {{ $restaurantPct }}%"></div></div>
                    </div>
                </div>

                <p class="mt-4 text-[22px] font-bold text-rg-text">{{ $originTotal === 1 && (($post->homemade_votes_count ?? 0) + ($post->restaurant_votes_count ?? 0)) === 0 ? 0 : $originTotal }} votes</p>

                <div class="mt-4" data-testid="post-drawer-origin-voting" wire:click.stop wire:keydown.stop>
                    <livewire:posts.origin-voting
                        :post-id="$post->id"
                        :key="'post-drawer-origin-voting-'.$post->id"
                    />
                </div>
            </div>

            <div>
                <h3 class="text-base font-bold text-rg-text">Cuisine guess</h3>
                <div class="mt-4" data-testid="post-drawer-cuisine-voting" wire:click.stop wire:keydown.stop>
                    <livewire:posts.cuisine-voting
                        :post-id="$post->id"
                        :key="'post-drawer-cuisine-voting-'.$post->id"
                    />
                </div>
            </div>
        </section>

        @if($showSharePanel)
            <div class="mt-6" data-testid="post-drawer-share-panel">
                <x-share.post-share-panel :post="$post" />
            </div>
        @endif

        <div class="mt-6 flex justify-end" data-testid="post-drawer-report">
            <livewire:reports.report-modal
                reportable-type="post"
                :reportable-id="$post->id"
                :key="'post-drawer-report-'.$post->id"
            />
        </div>

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
