@php
    $hasSelectedPost = $selectedPostId !== null;
@endphp

<div
    class="min-h-screen"
    data-testid="feed-page"
    x-data="{
        scrollToSelectedPost(postId) {
            this.$nextTick(() => {
                setTimeout(() => {
                    requestAnimationFrame(() => {
                        const feed = this.$refs.feedScroll;
                        if (feed) {
                            const card = feed.querySelector('[data-post-id=\'' + postId + '\']');
                            if (card) {
                                const top = card.getBoundingClientRect().top + window.scrollY - 80;
                                window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
                            }
                        }

                        if (this.$refs.detailScroll) {
                            this.$refs.detailScroll.scrollTo({ top: 0, behavior: 'auto' });
                        }
                    });
                }, 40);
            });
        }
    }"
    x-on:post-selected.window="scrollToSelectedPost($event.detail.postId)"
>
    <div
        class="{{ $hasSelectedPost
            ? 'grid min-w-0 gap-5 lg:grid-cols-[minmax(560px,1.4fr)_minmax(0,1fr)]'
            : 'grid min-w-0 lg:block' }}"
        data-testid="feed-content-shell"
    >
        <section
            x-ref="feedScroll"
            class="{{ $hasSelectedPost
                ? 'min-w-0'
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
                class="min-w-0 pt-5 lg:sticky lg:top-[80px] lg:max-h-[calc(100vh-100px)] lg:overflow-y-auto lg:pt-0"
            >
                <livewire:feed.post-drawer
                    :post-id="$selectedPostId"
                    :key="'detail-'.$selectedPostId"
                />
            </aside>
        @endif
    </div>
</div>
