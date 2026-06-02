<div data-testid="post-drawer">
    <div wire:loading data-testid="post-drawer-loading" class="space-y-4 transition-opacity duration-200">
        <x-ui.skeleton shape="block" height="16rem" />
        <x-ui.skeleton shape="line" width="70%" />
        <x-ui.skeleton shape="line" width="45%" />
    </div>

    <div wire:loading.remove class="overflow-y-auto transition-opacity duration-200">
    @if($post)
        <article x-data="{ shareOpen: false, menuOpen: false, deleteOpen: false, imageOpen: false }" class="relative rounded-rgCard border border-rg-border bg-rg-card p-5">
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
            <h2 data-testid="post-drawer-title" class="mt-2 pr-10 text-[22px] font-bold tracking-normal text-rg-text">{{ $post->title }}</h2>

            <div class="mt-4">
                @if($post->public_image_url)
                    <button
                        type="button"
                        class="block w-full cursor-zoom-in rounded-rgMedia focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                        x-on:click.stop="imageOpen = true"
                        data-testid="post-drawer-image-open"
                        aria-label="Open image fullscreen"
                    >
                        <img
                            src="{{ $post->public_image_url }}"
                            alt="{{ $post->title }}"
                            class="aspect-[4/3] w-full rounded-rgMedia object-cover"
                        >
                    </button>
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
                            variant="pill"
                            :key="'post-detail-vote-pill-'.$post->id"
                        />
                    </div>
                    <x-ui.action-button icon="comment">{{ $post->comments_count ?? 0 }}</x-ui.action-button>
                    <x-ui.action-button icon="share" x-on:click="shareOpen = true">Share</x-ui.action-button>
                    @auth
                        <livewire:posts.save-post-button
                            :post-id="$post->id"
                            :key="'post-drawer-save-'.$post->id"
                        />
                    @endauth
                </div>
                @if($canReportPost || $canDeletePost || $canModeratePost)
                <div class="relative" wire:click.stop wire:keydown.stop>
                    <button
                        type="button"
                        x-on:click="menuOpen = ! menuOpen"
                        class="cursor-pointer rounded-rgSm p-1 text-rg-muted transition hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                        aria-label="Post actions"
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
                            <div class="rounded-rgSm px-3 py-1.5 transition hover:bg-rg-card">
                                <livewire:reports.report-modal
                                    reportable-type="post"
                                    :reportable-id="$post->id"
                                    :key="'post-drawer-menu-report-'.$post->id"
                                />
                            </div>
                        @endif

                        @if($canDeletePost)
                            <button
                                type="button"
                                x-on:click="menuOpen = false; deleteOpen = true"
                                class="flex w-full cursor-pointer items-center rounded-rgSm px-3 py-1.5 text-left text-sm font-semibold text-rg-dangerText transition hover:bg-rg-dangerSoft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-dangerText"
                            >
                                Delete post
                            </button>
                        @endif

                        <livewire:moderation.inline-post-moderation
                            :post-id="$post->id"
                            variant="menu"
                            :key="'post-drawer-menu-moderation-'.$post->id"
                        />

                    </div>
                </div>
                @endif
            </footer>

            <x-ui.modal title="Share this post" state="shareOpen" size="lg">
                <x-share.post-share-panel :post="$post" />
            </x-ui.modal>

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

            <x-ui.modal title="Delete post?" state="deleteOpen" size="sm">
                <div class="space-y-4">
                    <p class="text-sm leading-6 text-rg-muted">This will remove the post from public feeds.</p>

                    @if($deleteError)
                        <p class="text-sm text-rg-dangerText">{{ $deleteError }}</p>
                    @endif

                    <div class="flex justify-end gap-2">
                        <x-ui.button type="button" variant="ghost" x-on:click="deleteOpen = false">Cancel</x-ui.button>
                        <x-ui.button
                            type="button"
                            variant="danger"
                            wire:click="deleteSelectedPost"
                            wire:loading.attr="disabled"
                            wire:target="deleteSelectedPost"
                        >
                            Delete
                        </x-ui.button>
                    </div>
                </div>
            </x-ui.modal>
        </article>

        <section data-testid="post-detail-results" class="mt-4 grid min-w-0 gap-5 rounded-rgCard border border-rg-border bg-rg-card p-4 sm:grid-cols-2 sm:p-5">
            <div class="min-w-0">
                <div class="mb-3 flex items-baseline gap-2">
                    <h3 class="text-base font-bold text-rg-text">Results</h3>
                </div>

                <div class="mb-3" data-testid="post-drawer-origin-voting" wire:click.stop wire:keydown.stop>
                    <livewire:posts.origin-voting
                        :post-id="$post->id"
                        :key="'post-drawer-origin-voting-'.$post->id"
                    />
                </div>

                @if($showOriginDistribution)
                    <div class="mb-1.5 flex justify-between gap-3">
                        <span class="text-[13px] font-semibold text-rg-good">Homemade</span>
                        <span class="text-[13px] text-rg-text2">Restaurant</span>
                    </div>
                    <div class="mb-2 flex justify-between gap-3">
                        <span class="text-[20px] font-bold text-rg-good">{{ $originDistribution['homemadePct'] }}% ({{ $originDistribution['homemade'] }})</span>
                        <span class="text-[20px] font-bold text-rg-text2">{{ $originDistribution['restaurantPct'] }}% ({{ $originDistribution['restaurant'] }})</span>
                    </div>
                    <div class="relative h-2 overflow-hidden rounded-rgPill bg-rg-card2">
                        <div class="absolute bottom-0 left-0 top-0 rounded-rgPill bg-rg-good" style="width: {{ $originDistribution['homemadePct'] }}%"></div>
                    </div>
                    <div class="mt-2.5 text-[11.5px] text-rg-muted">{{ $originDistribution['total'] }} votes</div>
                @endif
            </div>

            <div class="min-w-0">
                <h3 class="mb-3.5 text-sm font-bold text-rg-text">Cuisine guess distribution</h3>
                <div class="mb-3" data-testid="post-drawer-cuisine-voting" wire:click.stop wire:keydown.stop>
                    <livewire:posts.cuisine-voting
                        :post-id="$post->id"
                        variant="compact"
                        :key="'post-drawer-cuisine-voting-'.$post->id"
                    />
                </div>

                @if($showCuisineDistribution)
                    <div class="flex flex-col gap-2">
                        @foreach($cuisineDistribution['rows'] as $row)
                            <div class="grid min-w-0 grid-cols-[24px_minmax(0,1fr)_46px] items-center gap-2">
                                <span class="text-xs font-semibold text-rg-text2">{{ $row['label'] }}</span>
                                <div class="h-2 min-w-0 overflow-hidden rounded-rgPill bg-rg-card2">
                                    <div class="h-full rounded-rgPill bg-rg-accent" style="width: {{ $row['percentage'] }}%"></div>
                                </div>
                                <span class="text-right text-[11px] text-rg-text2">{{ $row['percentage'] }}% ({{ $row['count'] }})</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        <section class="mt-6" data-testid="drawer-comments-slot">
            <livewire:comments.comments-section :post-id="$post->id" :show-header="true" :key="'drawer-comments-'.$post->id" />
        </section>
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
