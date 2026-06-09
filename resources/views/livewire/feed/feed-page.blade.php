@php
    $hasSelectedPost = $selectedPostId !== null;
@endphp
@inject('settingsManager', \App\Support\Settings\ProjectSettingsManager::class)
@php $feedSettings = $settingsManager->current(); @endphp

<div
    class="min-h-screen min-w-0"
    data-testid="feed-page"
    data-screenshot="feed-page"
    x-data="{
        scrollToSelectedPost(postId) {
            this.$nextTick(() => {
                setTimeout(() => {
                    // Two nested rAFs: first lets Livewire update the DOM, second waits for layout recalc
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            const detail = this.$refs.detailScroll;

                            if (detail) {
                                detail.scrollTo({ top: 0, behavior: 'auto' });
                            }

                            if (window.innerWidth < 1024) {
                                if (detail) {
                                    const top = detail.getBoundingClientRect().top + window.scrollY - 80;
                                    window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
                                }
                                return;
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
                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button
                                type="button"
                                x-on:click="open = ! open"
                                class="flex h-9 cursor-pointer items-center gap-1.5 rounded-rgSm border border-rg-border2 bg-rg-card2 px-3 text-[12.5px] font-semibold text-rg-text2 transition hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                                data-testid="origin-filter-trigger"
                                aria-haspopup="true"
                                x-bind:aria-expanded="open"
                            >
                                {{ __('ui.voting.source') }}
                                @if(count((array) $origin) > 0)
                                    <span class="rounded-rgPill bg-rg-accentSoft px-1.5 text-[11px] text-rg-accent2">{{ count((array) $origin) }}</span>
                                @endif
                                <x-ui.icon name="chevron-down" class="size-3.5" />
                            </button>

                            <div
                                x-cloak
                                x-show="open"
                                class="absolute left-0 z-20 mt-2 w-48 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                                data-testid="origin-filter-menu"
                            >
                                @foreach($originOptions as $filter)
                                    <button
                                        type="button"
                                        wire:click="toggleOrigin('{{ $filter['value'] }}')"
                                        class="flex w-full cursor-pointer items-center gap-2 rounded-rgSm px-3 py-1.5 text-left text-[12.5px] font-semibold text-rg-text2 transition hover:bg-rg-card"
                                    >
                                        <input
                                            type="checkbox"
                                            @checked(in_array($filter['value'], (array) $origin, true))
                                            class="size-3.5 rounded border-rg-border2 bg-rg-card text-rg-accent focus:ring-rg-accent"
                                            tabindex="-1"
                                            readonly
                                        >
                                        {{ $filter['label'] }}
                                    </button>
                                @endforeach

                                <button
                                    type="button"
                                    wire:click="clearOriginFilters"
                                    class="mt-1 block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-[12px] font-semibold text-rg-muted transition hover:bg-rg-card hover:text-rg-text"
                                >
                                    {{ __('ui.feed.clear_filter') }}
                                </button>
                            </div>
                        </div>

                        <div class="relative" x-data="{ open: false }" x-on:click.outside="open = false">
                            <button
                                type="button"
                                x-on:click="open = ! open"
                                class="flex h-9 cursor-pointer items-center gap-1.5 rounded-rgSm border border-rg-border2 bg-rg-card2 px-3 text-[12.5px] font-semibold text-rg-text2 transition hover:bg-rg-cardHover hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                                data-testid="cuisine-filter-trigger"
                                aria-haspopup="true"
                                x-bind:aria-expanded="open"
                            >
                                {{ __('ui.voting.category') }}
                                @if(count((array) $cuisine) > 0)
                                    <span class="rounded-rgPill bg-rg-accentSoft px-1.5 text-[11px] text-rg-accent2">{{ count((array) $cuisine) }}</span>
                                @endif
                                <x-ui.icon name="chevron-down" class="size-3.5" />
                            </button>

                            <div
                                x-cloak
                                x-show="open"
                                class="absolute left-0 z-20 mt-2 w-52 rounded-rgControl border border-rg-border bg-rg-card2 p-1 shadow-rgDropdown"
                                data-testid="cuisine-filter-menu"
                            >
                                @foreach($cuisineOptions as $filter)
                                    <button
                                        type="button"
                                        wire:click="toggleCuisine('{{ $filter['value'] }}')"
                                        class="flex w-full cursor-pointer items-center gap-2 rounded-rgSm px-3 py-1.5 text-left text-[12.5px] font-semibold text-rg-text2 transition hover:bg-rg-card"
                                    >
                                        <input
                                            type="checkbox"
                                            @checked(in_array($filter['value'], (array) $cuisine, true))
                                            class="size-3.5 rounded border-rg-border2 bg-rg-card text-rg-accent focus:ring-rg-accent"
                                            tabindex="-1"
                                            readonly
                                        >
                                        {{ $filter['label'] }}
                                    </button>
                                @endforeach

                                <button
                                    type="button"
                                    wire:click="clearCuisineFilters"
                                    class="mt-1 block w-full cursor-pointer rounded-rgSm px-3 py-1.5 text-left text-[12px] font-semibold text-rg-muted transition hover:bg-rg-card hover:text-rg-text"
                                >
                                    {{ __('ui.feed.clear_filter') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <livewire:feed.sort-dropdown wire:model.live="sort" />
            </div>

            <h2 class="mb-4 text-base font-semibold text-rg-text2" data-testid="feed-title">{{ $feedSettings->feedTitle() }}</h2>
            <livewire:feed.post-feed
                :search="$this->effectiveSearch()"
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
