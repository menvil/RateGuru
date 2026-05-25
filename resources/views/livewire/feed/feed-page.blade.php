<div
    class="min-h-screen"
    x-data="{ drawerOpen: false }"
    data-testid="post-detail-drawer-shell"
    @post-drawer-opened.window="drawerOpen = true; $dispatch('open-drawer', { id: 'post-detail-drawer' })"
    @drawer-closed.window="if ($event.detail?.id === 'post-detail-drawer') { drawerOpen = false; $wire.closePostDrawer() }"
>
    <section class="mx-auto w-full max-w-2xl px-4 py-5 sm:px-6 lg:max-w-3xl lg:px-8 lg:py-8" data-testid="feed-page">
        <header class="mb-5">
            <h1 class="text-2xl font-bold text-rg-text sm:text-3xl">RateGuru</h1>
            <p class="mt-1 text-sm text-rg-muted">Discover dishes</p>
        </header>

        <div class="mb-5">
            <div class="flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <livewire:feed.category-tabs wire:model.live="category" />
                </div>
                <livewire:feed.sort-dropdown wire:model.live="sort" />
            </div>
        </div>

        <main data-testid="feed-layout">
            <section>
                <h2 class="mb-4 text-base font-semibold text-rg-text2">Latest dishes</h2>
                <livewire:feed.post-feed
                    :search="$search"
                    :tag="$category"
                    :sort="$sort"
                    :key="'feed-'.md5(json_encode([$search, $category, $sort]))"
                />
            </section>
        </main>
    </section>

    <x-ui.drawer
        id="post-detail-drawer"
        title="Dish details"
    >
        <livewire:feed.post-drawer
            :post-id="$selectedPostId"
            :key="'drawer-'.($selectedPostId ?? 'empty')"
        />
    </x-ui.drawer>
</div>
