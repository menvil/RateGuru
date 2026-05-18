<div class="min-h-screen">
    <section class="mx-auto w-full max-w-xl px-4 py-6 sm:px-6 lg:py-10">
        <header class="mb-6">
            <h1 class="text-2xl font-bold text-rg-text sm:text-3xl">RateGuru</h1>
            <p class="mt-1 text-sm text-rg-muted">Discover dishes</p>
        </header>

        <div class="mb-3">
            <livewire:feed.search-bar wire:model.live="search" />
        </div>

        <div class="mb-3 flex items-center gap-3">
            <div class="min-w-0 flex-1">
                <livewire:feed.category-tabs wire:model.live="category" />
            </div>
            <livewire:feed.sort-dropdown wire:model.live="sort" />
        </div>

        <main>
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
</div>
