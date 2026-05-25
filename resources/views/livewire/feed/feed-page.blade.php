@php($hasSelectedPost = $selectedPostId !== null)

<div
    class="min-h-screen lg:min-h-0"
    data-testid="feed-page"
    x-data
    x-on:post-selected.window="
        requestAnimationFrame(() => {
            const feed = $refs.feedScroll;
            if (feed) {
                const card = feed.querySelector('[data-post-id=\'' + $event.detail.postId + '\']');
                if (card) {
                    feed.scrollTo({ top: Math.max(card.offsetTop - 80, 0), behavior: 'smooth' });
                }
            }

            if ($refs.detailScroll) {
                $refs.detailScroll.scrollTo({ top: 0, behavior: 'auto' });
            }
        })
    "
>
    <div
        class="{{ $hasSelectedPost
            ? 'grid min-w-0 gap-0 lg:h-[calc(100vh-92px)] lg:grid-cols-[minmax(560px,1.4fr)_minmax(0,1fr)] lg:overflow-hidden'
            : 'grid min-w-0 lg:block' }}"
        data-testid="feed-content-shell"
    >
        <section
            x-ref="feedScroll"
            class="{{ $hasSelectedPost
                ? 'min-w-0 lg:overflow-y-auto lg:border-r lg:border-rg-border lg:pr-5'
                : 'mx-auto min-w-0 max-w-[820px]' }}"
            data-testid="feed-layout"
        >
            <div class="mb-5 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <livewire:feed.category-tabs wire:model.live="category" />
                </div>
                <livewire:feed.sort-dropdown wire:model.live="sort" />
            </div>

            <h2 class="mb-4 text-base font-semibold text-rg-text2">Latest dishes</h2>
            <livewire:feed.post-feed
                :search="$search"
                :tag="$category"
                :sort="$sort"
                :selected-post-id="$selectedPostId"
                :key="'feed-'.md5(json_encode([$search, $category, $sort, $selectedPostId]))"
            />
        </section>

        @if($hasSelectedPost)
            <aside
                x-ref="detailScroll"
                data-testid="post-detail-column"
                class="min-w-0 pt-5 lg:overflow-y-auto lg:pl-7 lg:pt-0"
            >
                <livewire:feed.post-drawer
                    :post-id="$selectedPostId"
                    :key="'detail-'.$selectedPostId"
                />
            </aside>
        @endif
    </div>
</div>
