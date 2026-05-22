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
                {{-- Profile actions are added in later Phase 30 tasks. --}}
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
</div>
