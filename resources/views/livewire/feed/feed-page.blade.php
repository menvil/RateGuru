<div class="min-h-screen" data-testid="feed-page">
    <div class="grid min-w-0 gap-5 lg:grid-cols-[minmax(520px,1fr)_minmax(380px,460px)]">
        <section class="min-w-0 lg:border-r lg:border-rg-border lg:pr-5" data-testid="feed-layout">
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

        <aside data-testid="post-detail-column" class="min-w-0 lg:sticky lg:top-[76px] lg:max-h-[calc(100vh-92px)] lg:overflow-y-auto">
            <livewire:feed.post-drawer
                :post-id="$selectedPostId"
                :key="'detail-'.($selectedPostId ?? 'empty')"
            />
        </aside>
    </div>
</div>
