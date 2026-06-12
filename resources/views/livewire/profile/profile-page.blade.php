<div data-testid="profile-page" class="mx-auto w-full max-w-4xl">

    {{-- Profile Header --}}
    <section
        data-testid="profile-header"
        data-screenshot="profile-header"
        class="rounded-rgCard border border-rg-border bg-rg-card p-5 sm:p-6"
    >
        <div class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                <div data-testid="profile-avatar">
                    <x-ui.avatar
                        :src="$profileUser->avatar_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($profileUser->avatar_path) : $profileUser->avatar_url"
                        :name="$this->displayName"
                        size="xl"
                    />
                </div>

                <div data-testid="profile-identity" class="min-w-0">
                    <h1 class="truncate text-2xl font-semibold text-rg-text">{{ $this->displayName }}</h1>
                    <p class="mt-0.5 text-sm text-rg-muted">{{ '@' . $profileUser->username }}</p>

                    @if($profileUser->bio)
                        <p class="mt-2 max-w-md text-sm leading-relaxed text-rg-text2" data-testid="profile-bio">{{ $profileUser->bio }}</p>
                    @endif

                    @if($profileUser->profile_website_url)
                        <a
                            href="{{ $profileUser->profile_website_url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-1 inline-flex items-center gap-1 text-xs text-rg-accent hover:underline"
                            data-testid="profile-website"
                        >
                            {{ $profileUser->profile_website_url }}
                        </a>
                    @endif
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                @if($this->isOwner)
                    <a
                        href="{{ route('profile.edit') }}"
                        data-testid="edit-profile-link"
                        class="inline-flex h-8 cursor-pointer items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-3 text-xs font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                    >
                        {{ __('profile.edit_profile') }}
                    </a>
                @endif

                @if($this->canSeeFollowButton)
                    <livewire:follows.follow-button :author="$profileUser" />
                @endif

                @if($this->canSeeReportUserPlaceholder)
                    <div data-testid="report-user-button" x-data="{ reportOpen: false }">
                        <x-ui.button
                            type="button"
                            variant="ghost"
                            size="sm"
                            x-on:click="reportOpen = true"
                        >
                            {{ __('ui.user.report') }}
                        </x-ui.button>
                        <x-ui.modal :title="__('ui.user.report_title')" state="reportOpen" size="md">
                            <livewire:reports.report-modal
                                reportable-type="user"
                                :reportable-id="$profileUser->id"
                                variant="inline"
                                :key="'profile-report-user-'.$profileUser->id"
                            />
                        </x-ui.modal>
                    </div>
                @endif
            </div>
        </div>

        {{-- Stats Row --}}
        <div data-testid="profile-stats" class="mt-6 flex flex-wrap gap-4">
            <div class="text-center">
                <div class="text-lg font-bold text-rg-text">{{ $this->stats->publicPostsCount }}</div>
                <div class="text-xs font-medium text-rg-muted">{{ __('profile.posts') }}</div>
            </div>
            <div class="text-center" data-testid="followers-count">
                <div class="text-lg font-bold text-rg-text">{{ $this->stats->followersCount }}</div>
                <div class="text-xs font-medium text-rg-muted">{{ __('follows.followers') }}</div>
            </div>
            <div class="text-center" data-testid="following-count">
                <div class="text-lg font-bold text-rg-text">{{ $this->stats->followingCount }}</div>
                <div class="text-xs font-medium text-rg-muted">{{ __('follows.following_count') }}</div>
            </div>
            @if($this->isOwner && $this->stats->savedPostsCount !== null)
                <div class="text-center" data-testid="saved-count">
                    <div class="text-lg font-bold text-rg-text">{{ $this->stats->savedPostsCount }}</div>
                    <div class="text-xs font-medium text-rg-muted">{{ __('profile.saved') }}</div>
                </div>
            @endif
        </div>
    </section>

    {{-- Profile Tabs --}}
    <div data-testid="profile-tabs" class="mt-6">
        <div class="flex items-center justify-between border-b border-rg-border">
            <div class="flex overflow-x-auto">
            <button
                wire:click="setTab('posts')"
                data-testid="profile-tab-posts"
                class="shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none {{ $tab === 'posts' ? 'border-rg-accent text-rg-accent' : 'border-transparent text-rg-muted hover:text-rg-text' }}"
            >
                {{ __('profile.posts') }}
            </button>

            @if($this->canSeeActivity)
            <button
                wire:click="setTab('activity')"
                data-testid="profile-tab-activity"
                class="shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none {{ $tab === 'activity' ? 'border-rg-accent text-rg-accent' : 'border-transparent text-rg-muted hover:text-rg-text' }}"
            >
                {{ __('profile.activity') }}
            </button>
            @endif

            @if($this->isOwner)
                <button
                    wire:click="setTab('saved')"
                    data-testid="profile-tab-saved"
                    class="shrink-0 border-b-2 px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none {{ $tab === 'saved' ? 'border-rg-accent text-rg-accent' : 'border-transparent text-rg-muted hover:text-rg-text' }}"
                >
                    {{ __('profile.saved') }}
                </button>
            @endif
            </div>

            @if($this->canSeeFollowButton)
                <div class="shrink-0 pb-1">
                    <livewire:follows.follow-button :author="$profileUser" />
                </div>
            @endif
        </div>

        {{-- Tab Content --}}
        <div class="mt-6" data-testid="profile-tab-content">
            @if($tab === 'posts')
                <div data-testid="profile-posts-tab">
                    @if($this->posts->isEmpty())
                        <x-ui.empty-state
                            :title="__('profile.no_posts')"
                            :description="__('profile.no_posts_description')"
                        />
                    @else
                        <div data-testid="profile-posts-grid" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($this->posts as $post)
                                <a href="{{ route('posts.show', $post) }}" wire:navigate data-testid="profile-post-card-link">
                                <x-ui.card variant="post" data-testid="profile-post-card" class="h-full transition-colors hover:border-rg-border2 hover:bg-rg-cardHover">
                                    @if($post->public_image_url)
                                        <img
                                            src="{{ $post->public_image_url }}"
                                            alt="{{ $post->title }}"
                                            class="aspect-video w-full rounded-rgMedia object-cover"
                                        >
                                    @else
                                        <x-ui.image-placeholder :label="__('profile.post_image')" ratio="feed" />
                                    @endif

                                    <div class="mt-3">
                                        <h3 class="text-base font-bold text-rg-text">{{ $post->title }}</h3>

                                        @if($post->truncated_description)
                                            <p class="mt-1 text-[13px] leading-snug text-rg-muted">{{ $post->truncated_description }}</p>
                                        @endif
                                    </div>

                                    <footer class="mt-3 flex items-center gap-4 border-t border-rg-border pt-2.5 text-xs text-rg-muted">
                                        <span>{{ __('profile.score') }} <span class="font-semibold text-rg-text2">{{ $post->score }}</span></span>
                                        <span>{{ __('profile.comments', ['count' => $post->comments_count ?? 0]) }}</span>
                                    </footer>
                                </x-ui.card>
                                </a>
                            @endforeach
                        </div>

                        <div class="mt-6">
                            {{ $this->posts->links() }}
                        </div>
                    @endif
                </div>

            @elseif($tab === 'activity')
                <div data-testid="profile-activity-tab">
                    @if(! $this->canSeeActivity)
                        <div data-testid="profile-activity-private" class="py-8 text-center text-sm text-rg-muted">
                            {{ __('profile.activity_private') }}
                        </div>
                    @elseif($this->ratingActivity->isEmpty())
                        <x-ui.empty-state
                            :title="__('profile.no_activity')"
                            description=""
                        />
                    @else
                        <div class="space-y-3">
                            @foreach($this->ratingActivity as $vote)
                                <div class="rounded-rgCard border border-rg-border bg-rg-card p-4" data-testid="profile-activity-item">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-rg-text">{{ $vote->post->title ?? '—' }}</p>
                                            @if($vote->group && $vote->option)
                                                <p class="mt-1 text-xs text-rg-muted">
                                                    {{ $vote->group->label ?? $vote->group->name ?? '' }}:
                                                    <span class="font-medium text-rg-text2">{{ $vote->option->label ?? $vote->option->name ?? '' }}</span>
                                                </p>
                                            @endif
                                        </div>
                                        <time class="shrink-0 text-xs text-rg-muted">{{ $vote->created_at->diffForHumans() }}</time>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

            @elseif($tab === 'saved' && $this->isOwner)
                <div data-testid="profile-saved-tab">
                    @if($this->savedPosts?->isEmpty())
                        <x-ui.empty-state
                            :title="__('profile.saved')"
                            description=""
                        />
                    @elseif($this->savedPosts)
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach($this->savedPosts as $post)
                                <x-ui.card variant="post" data-testid="profile-saved-post-card">
                                    @if($post->public_image_url)
                                        <img
                                            src="{{ $post->public_image_url }}"
                                            alt="{{ $post->title }}"
                                            class="aspect-video w-full rounded-rgMedia object-cover"
                                        >
                                    @else
                                        <x-ui.image-placeholder :label="__('profile.post_image')" ratio="feed" />
                                    @endif
                                    <div class="mt-3">
                                        <h3 class="text-base font-bold text-rg-text">{{ $post->title }}</h3>
                                    </div>
                                </x-ui.card>
                            @endforeach
                        </div>
                        <div class="mt-6">
                            {{ $this->savedPosts->links() }}
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
