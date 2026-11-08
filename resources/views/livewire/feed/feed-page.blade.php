@php
    $hasSelectedPost = $selectedPostId !== null;
@endphp
@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php
    $feedSettings = $settingsManager->current();
    $overlayMode = $feedSettings->featureFlag('post_detail_overlay_mode', false);
    // When overlay mode is on, the layout-level global overlay (layouts/app.blade.php)
    // handles the post detail panel instead of this inline split-grid column.
    $splitMode = $hasSelectedPost && ! $overlayMode;
@endphp

<div
    class="min-h-screen min-w-0"
    data-testid="feed-page"
    data-screenshot="feed-page"
    x-data="{
        scrollToSelectedPost(postId) {
            if (window.innerWidth < 1024) return;

            this.$nextTick(() => {
                setTimeout(() => {
                    // Two nested rAFs: first lets Livewire update the DOM, second waits for layout recalc
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            const detail = this.$refs.detailScroll;

                            if (detail) {
                                detail.scrollTo({ top: 0, behavior: 'auto' });
                            }

                            const feed = this.$refs.feedScroll;
                            if (!feed) return;
                            const card = feed.querySelector('[data-post-id=\'' + postId + '\']');
                            if (!card) return;
                            const top = card.getBoundingClientRect().top + window.scrollY - 80;
                            window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
                        });
                    });
                }, 40);
            });
        },
        scrollToDetailTarget(target) {
            if (window.innerWidth < 1024) return;

            if (!target) {
                return;
            }

            this.$nextTick(() => {
                setTimeout(() => {
                    requestAnimationFrame(() => {
                        const detail = this.$refs.detailScroll;
                        const selector = target === 'comments' ? '[data-testid=\'drawer-comments-slot\']' : null;
                        const targetEl = selector && detail ? detail.querySelector(selector) : null;

                        if (detail && targetEl) {
                            detail.scrollTo({ top: targetEl.offsetTop - 12, behavior: 'smooth' });
                        }
                    });
                }, 80);
            });
        }
    }"
    x-on:post-selected.window="scrollToSelectedPost($event.detail.postId); scrollToDetailTarget($event.detail.focus)"
    x-on:post-comments-requested.window="scrollToDetailTarget('comments')"
    {{-- Overlay mode always marks the selected card; the split view selects only where its
         detail column is drawn, on a desktop. See FeedPage::selectPost(). --}}
    x-on:select-post.window="if (@js($overlayMode) || window.innerWidth >= 1024) $wire.selectPost($event.detail.postId, $event.detail.focus ?? null)"
>
    <div
        class="{{ $splitMode
            ? 'rg-feed-split-grid min-w-0 gap-5'
            : 'grid min-w-0 lg:block' }}"
        data-testid="feed-content-shell"
    >
        <section
            x-ref="feedScroll"
            class="{{ $splitMode
                ? 'min-w-0 lg:border-r lg:border-rg-border lg:pr-5'
                : 'mx-auto min-w-0 max-w-[820px]' }}"
            data-testid="feed-layout"
        >
            @if(filled($tag))
                <div class="mb-4 flex flex-wrap items-center gap-2" data-testid="feed-tag-filter">
                    <span class="text-[13px] text-rg-muted">{{ __('ui.feed.tag_filter') }}</span>
                    <span class="inline-flex items-center gap-0.5 rounded-rgPill border border-rg-accentBorder bg-rg-accentSoft pl-2.5 pr-0.5 text-xs leading-5 font-semibold text-rg-text">
                        <span data-testid="feed-tag-filter-name">#{{ $tag }}</span>
                        <a
                            href="{{ $this->urlWithoutTag() }}"
                            wire:navigate
                            aria-label="{{ __('ui.feed.clear_tag_filter', ['tag' => '#'.$tag]) }}"
                            title="{{ __('ui.feed.clear_tag_filter', ['tag' => '#'.$tag]) }}"
                            data-testid="feed-tag-filter-clear"
                            class="grid size-5 place-items-center rounded-full text-rg-text2 transition hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                        >
                            <x-ui.icon name="x" class="size-3" />
                        </a>
                    </span>
                </div>
            @endif

            @php $matchedUsers = $this->matchedUsers(); @endphp
            @if($matchedUsers->isNotEmpty())
                <div class="mb-5 rounded-rgCard border border-rg-border bg-rg-card p-4" data-testid="feed-user-results">
                    <p class="mb-3 text-[11px] font-bold uppercase tracking-[0.12em] text-rg-muted">{{ __('ui.feed.users_heading') }}</p>
                    <ul class="space-y-1">
                        @foreach($matchedUsers as $matchedUser)
                            <li>
                                <a
                                    href="{{ route('profile.show', ['username' => $matchedUser->username]) }}"
                                    class="flex items-center gap-3 rounded-rgSm px-2 py-1.5 transition hover:bg-rg-card2"
                                    data-testid="feed-user-result-{{ $matchedUser->id }}"
                                >
                                    <x-ui.avatar
                                        :src="$matchedUser->resolved_avatar_url"
                                        :srcset="$matchedUser->resolved_avatar_srcset"
                                        :name="$matchedUser->resolved_display_name"
                                        color="purple"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-rg-text">{{ $matchedUser->resolved_display_name }}</span>
                                        <span class="block truncate text-xs text-rg-muted">{{ '@'.$matchedUser->username }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <livewire:feed.post-feed
                :search="$this->effectiveSearch()"
                :tag="$tag"
                :category="$category"
                :ratings="$ratings"
                :sort="$sort"
                :following-only="$this->isFollowingFeed()"
                :selected-post-id="$selectedPostId"
                :key="'feed-'.md5(json_encode([$search, $tag, $category, $ratings, $sort, $feed]))"
            />
        </section>

        @if($splitMode)
            <aside
                x-ref="detailScroll"
                data-testid="post-detail-column"
                class="hidden min-w-0 lg:block lg:sticky lg:top-[80px] lg:max-h-[calc(100vh-100px)] lg:overflow-y-auto lg:pl-5 lg:pr-5"
            >
                <livewire:feed.post-drawer
                    :post-id="$selectedPostId"
                    :key="'detail-'.$selectedPostId"
                />
            </aside>
        @endif
    </div>

    @unless($overlayMode)
        <livewire:feed.post-drawer
            :key="'mobile-split-mode-post-overlay'"
            :as-overlay="true"
            :mobile-only="true"
        />
    @endunless
</div>
