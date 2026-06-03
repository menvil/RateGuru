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
        },
        scrollToDetailTarget(target) {
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
>
    <div
        class="{{ $hasSelectedPost
            ? 'grid min-w-0 gap-5 lg:grid-cols-[minmax(560px,1.4fr)_minmax(0,1fr)] lg:gap-0'
            : 'grid min-w-0 lg:block' }}"
        data-testid="feed-content-shell"
    >
        <section
            x-ref="feedScroll"
            class="{{ $hasSelectedPost
                ? 'min-w-0 lg:border-r lg:border-rg-border lg:pr-5'
                : 'mx-auto min-w-0 max-w-[820px]' }}"
            data-testid="feed-layout"
        >
            <div class="mb-5 flex flex-wrap items-center gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2" data-testid="feed-rating-filters">
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="mr-0.5 text-[12px] font-semibold text-rg-muted">Origin</span>
                            @foreach([
                                ['value' => null, 'label' => 'Any'],
                                ['value' => 'homemade', 'label' => 'Homemade'],
                                ['value' => 'restaurant', 'label' => 'Restaurant'],
                            ] as $filter)
                                <button
                                    type="button"
                                    wire:click="$set('origin', @js($filter['value']))"
                                    aria-pressed="{{ $origin === $filter['value'] ? 'true' : 'false' }}"
                                    class="h-8 cursor-pointer rounded-rgSm border px-2.5 text-[12.5px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $origin === $filter['value'] ? 'border-rg-accent bg-rg-accentSoft text-rg-accent2' : 'border-rg-border2 bg-rg-card2 text-rg-text2 hover:bg-rg-cardHover hover:text-rg-text' }}"
                                >
                                    {{ $filter['label'] }}
                                </button>
                            @endforeach
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="mr-0.5 text-[12px] font-semibold text-rg-muted">Cuisine guess:</span>
                            @foreach([
                                ['value' => null, 'label' => 'Any'],
                                ['value' => 'italian', 'label' => 'IT'],
                                ['value' => 'asian', 'label' => 'AS'],
                                ['value' => 'american', 'label' => 'US'],
                                ['value' => 'mexican', 'label' => 'MX'],
                                ['value' => 'other', 'label' => 'OT'],
                            ] as $filter)
                                <button
                                    type="button"
                                    wire:click="$set('cuisine', @js($filter['value']))"
                                    aria-pressed="{{ $cuisine === $filter['value'] ? 'true' : 'false' }}"
                                    class="h-8 min-w-9 cursor-pointer rounded-rgSm border px-2 text-[12px] font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent {{ $cuisine === $filter['value'] ? 'border-rg-accent bg-rg-accentSoft text-rg-accent2' : 'border-rg-border2 bg-rg-card2 text-rg-text2 hover:bg-rg-cardHover hover:text-rg-text' }}"
                                >
                                    {{ $filter['label'] }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
                <livewire:feed.sort-dropdown wire:model.live="sort" />
            </div>

            <h2 class="mb-4 text-base font-semibold text-rg-text2">Latest dishes</h2>
            <livewire:feed.post-feed
                :search="$search"
                :tag="$category"
                :origin="$origin"
                :cuisine="$cuisine"
                :sort="$sort"
                :selected-post-id="$selectedPostId"
                :key="'feed-'.md5(json_encode([$search, $category, $origin, $cuisine, $sort]))"
            />
        </section>

        @if($hasSelectedPost)
            <aside
                x-ref="detailScroll"
                data-testid="post-detail-column"
                class="min-w-0 pt-5 lg:sticky lg:top-[80px] lg:max-h-[calc(100vh-100px)] lg:overflow-y-auto lg:pl-5 lg:pr-5 lg:pt-0"
            >
                <livewire:feed.post-drawer
                    :post-id="$selectedPostId"
                    :key="'detail-'.$selectedPostId"
                />
            </aside>
        @endif
    </div>
</div>
