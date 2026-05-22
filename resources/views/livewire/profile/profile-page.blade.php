<div data-testid="profile-page" class="mx-auto w-full max-w-4xl">
    <section
        data-testid="profile-header"
        class="rounded-rgCard border border-rg-border bg-rg-card p-5 shadow-rgPopover sm:p-6"
    >
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div data-testid="profile-avatar">
                    <x-ui.avatar
                        :src="$profileUser->avatar_url"
                        :name="$profileUser->name ?? $profileUser->username"
                        size="xl"
                    />
                </div>

                <div data-testid="profile-identity" class="min-w-0">
                    <h1 class="text-2xl font-semibold text-rg-text">{{ $profileUser->name ?: $profileUser->username }}</h1>
                    <p class="mt-1 text-sm text-rg-muted">{{ '@' . $profileUser->username }}</p>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if($this->isOwner)
                    <x-ui.button
                        variant="secondary"
                        size="sm"
                        disabled
                        data-testid="edit-profile-placeholder"
                        title="Profile editing coming soon"
                    >
                        Edit profile
                    </x-ui.button>
                @endif

                @if($this->canSeeReportUserPlaceholder)
                    <x-ui.button
                        variant="danger"
                        size="sm"
                        disabled
                        data-testid="report-user-placeholder"
                        title="User reporting is coming soon"
                    >
                        Report user
                    </x-ui.button>
                @endif
            </div>
        </div>

        <div data-testid="profile-stats" class="mt-6 grid gap-3 sm:grid-cols-3">
            <x-ui.card padding="sm" class="text-center">
                <div class="text-xs font-medium text-rg-muted">Published posts</div>
                <div class="mt-1 text-lg font-bold text-rg-text">{{ $this->stats['published_posts'] }}</div>
            </x-ui.card>

            <x-ui.card padding="sm" class="text-center">
                <div class="text-xs font-medium text-rg-muted">Total upvotes</div>
                <div class="mt-1 text-lg font-bold text-rg-text">{{ $this->stats['total_upvotes'] }}</div>
            </x-ui.card>

            <x-ui.card padding="sm" class="text-center">
                <div class="text-xs font-medium text-rg-muted">Comments received</div>
                <div class="mt-1 text-lg font-bold text-rg-text">{{ $this->stats['comments_received'] }}</div>
            </x-ui.card>
        </div>
    </section>

    <section data-testid="profile-posts" class="mt-8">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-base font-semibold text-rg-text">Posts</h2>
            <span class="text-xs text-rg-muted">{{ $this->posts->total() }}</span>
        </div>

        @if($this->posts->isEmpty())
            <x-ui.empty-state
                title="No published posts yet"
                description="This user has not published any posts yet."
            />
        @else
            <div data-testid="profile-posts-grid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->posts as $post)
                    <x-ui.card variant="post" data-testid="profile-post-card">
                        @if($post->image_url)
                            <img
                                src="{{ $post->image_url }}"
                                alt="{{ $post->title }}"
                                class="aspect-video w-full rounded-rgMedia object-cover"
                            >
                        @else
                            <x-ui.image-placeholder label="Food image" ratio="feed" />
                        @endif

                        <div class="mt-3">
                            <h3 class="text-base font-bold text-rg-text">{{ $post->title }}</h3>

                            @if($post->truncated_description)
                                <p class="mt-1 text-[13px] leading-snug text-rg-muted">{{ $post->truncated_description }}</p>
                            @endif
                        </div>

                        <footer class="mt-3 flex items-center gap-4 border-t border-rg-border pt-2.5 text-xs text-rg-muted">
                            <span>Score <span class="font-semibold text-rg-text2">{{ $post->score }}</span></span>
                            <span>{{ $post->comments_count ?? 0 }} comments</span>
                        </footer>
                    </x-ui.card>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $this->posts->links() }}
            </div>
        @endif
    </section>
</div>
