{{--
    The Admin v2 sidebar. CSS decides the mode by width: the full 300px
    sidebar from 1280px, the 68px rail from 1024px, nothing below 1024px. The
    rail's expand button and the top bar's menu button open the same sidebar
    as a 300px overlay over a scrim; Escape, the scrim, the close button and
    following a link close it, and focus returns to the button that opened it.
--}}
<div
    class="rg-admin rg-admin-shell-sidebar"
    x-data="{
        open: false,
        trigger: null,
        tip: { text: '', top: 0, shown: false },
        show(trigger) {
            this.trigger = trigger ?? null
            this.open = true
            this.$dispatch('rg-admin-nav-state', { open: true })
            this.$nextTick(() => this.$refs.close?.focus())
        },
        hide(restoreFocus = true) {
            if (! this.open) {
                return
            }

            this.open = false
            this.$dispatch('rg-admin-nav-state', { open: false })

            if (restoreFocus) {
                this.$nextTick(() => this.trigger?.focus())
            }
        },
        showTip(el) {
            if (this.open || ! window.matchMedia('(min-width: 1024px) and (max-width: 1279.98px)').matches) {
                return
            }

            const box = el.getBoundingClientRect()

            this.tip = { text: el.dataset.tooltip, top: box.top + box.height / 2, shown: true }
        },
        hideTip() {
            this.tip.shown = false
        },
        focusSearch(event) {
            const search = document.getElementById('rg-admin-search')

            // A rich text editor keeps ⌘K / Ctrl+K for inserting a link.
            if (! search || event.defaultPrevented || event.target.isContentEditable) {
                return
            }

            event.preventDefault()

            // Collapsed into the rail or hidden on a narrow screen: open the
            // full sidebar first, so the field is there to type in.
            if (! this.open && ! window.matchMedia('(min-width: 1280px)').matches) {
                this.show(document.activeElement)
            }

            this.$nextTick(() => search.focus())
        },
    }"
    x-on:rg-admin-nav-open.window="show($event.detail?.trigger)"
    x-on:keydown.escape.window="hide()"
    x-on:keydown.meta.k.window="focusSearch($event)"
    x-on:keydown.ctrl.k.window="focusSearch($event)"
>
    <div
        class="rg-admin-shell-scrim"
        x-cloak
        x-show="open"
        x-on:click="hide()"
        aria-hidden="true"
    ></div>

    {{-- The rail's labels, on hover and keyboard focus. Each icon also carries its label for screen readers. --}}
    <div
        class="rg-admin-shell-tooltip"
        x-cloak
        x-show="tip.shown"
        x-bind:style="{ top: tip.top + 'px' }"
        x-text="tip.text"
        aria-hidden="true"
    ></div>

    <aside
        id="rg-admin-sidebar"
        class="rg-admin-sidebar"
        x-bind:class="{ 'rg-admin-sidebar--open': open }"
    >
        <div class="rg-admin-sidebar__header">
            <div class="rg-admin-workspace">
                <span class="rg-admin-workspace__mark" aria-hidden="true">R</span>
                <span class="rg-admin-workspace__text">
                    <span class="rg-admin-workspace__name">RateGuru</span>
                    <span class="rg-admin-workspace__subtitle">Admin</span>
                </span>
            </div>
            <x-admin.ui.icon-button
                icon="x"
                label="Close navigation"
                variant="ghost"
                size="sm"
                class="rg-admin-sidebar__close"
                aria-controls="rg-admin-sidebar"
                x-ref="close"
                x-on:click="hide()"
            />
        </div>

        @if ($hasGlobalSearch)
            <div class="rg-admin-sidebar__search">
                @livewire(\App\Livewire\Admin\GlobalSearch::class)
            </div>
        @endif

        <div class="rg-admin-sidebar__expand">
            <x-admin.ui.icon-button
                icon="panel-left-open"
                label="Expand navigation"
                variant="ghost"
                aria-controls="rg-admin-sidebar"
                x-bind:aria-expanded="open"
                x-on:click="show($el)"
            />
        </div>

        <nav class="rg-admin-sidebar__nav" aria-label="Admin navigation" x-on:scroll="hideTip()">
            @foreach ($sections as $section)
                @php
                    $sectionId = 'rg-admin-nav-'.\Illuminate\Support\Str::slug($section['label'] ?? 'general');
                @endphp
                <div class="rg-admin-nav-section">
                    @if (filled($section['label']))
                        <p class="rg-admin-nav-section__label" id="{{ $sectionId }}">{{ $section['label'] }}</p>
                    @endif
                    <span class="rg-admin-nav-section__separator" aria-hidden="true"></span>
                    <ul
                        class="rg-admin-nav-section__items"
                        @if (filled($section['label'])) aria-labelledby="{{ $sectionId }}" @endif
                    >
                        @foreach ($section['items'] as $item)
                            <li>
                                <a
                                    href="{{ $item['url'] }}"
                                    class="rg-admin-nav-item"
                                    data-tooltip="{{ $item['label'] }}"
                                    @if ($item['active']) aria-current="page" @endif
                                    @if ($item['newTab']) target="_blank" rel="noopener" @endif
                                    x-on:click="hide(false)"
                                    x-on:mouseenter="showTip($el)"
                                    x-on:mouseleave="hideTip()"
                                    x-on:focus="showTip($el)"
                                    x-on:blur="hideTip()"
                                >
                                    <x-admin.ui.icon :name="$item['icon']" :size="18" class="rg-admin-nav-item__icon" />
                                    <span class="rg-admin-nav-item__label">{{ $item['label'] }}</span>
                                    @if ($item['badge'] !== null)
                                        <x-admin.ui.badge
                                            :tone="$item['badgeTone']"
                                            pill
                                            class="rg-admin-nav-item__badge"
                                            :title="$item['badgeLabel']"
                                        >{{ $item['badge'] }}</x-admin.ui.badge>
                                        <span
                                            @class(['rg-admin-nav-item__dot', 'rg-admin-nav-item__dot--'.$item['badgeTone']])
                                            aria-hidden="true"
                                        ></span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        @if ($account !== null)
            <div
                class="rg-admin-sidebar__account"
                x-data="{ menu: false }"
                x-on:click.outside="menu = false"
                x-on:keydown.escape="if (menu) { $event.stopPropagation(); menu = false; $refs.accountButton.focus() }"
            >
                <button
                    type="button"
                    class="rg-admin-account"
                    aria-controls="rg-admin-account-menu"
                    x-ref="accountButton"
                    x-bind:aria-expanded="menu"
                    x-on:click="menu = ! menu; hideTip()"
                    data-tooltip="{{ $account['name'] }}"
                    x-on:mouseenter="if (! menu) showTip($el)"
                    x-on:mouseleave="hideTip()"
                    x-on:focus="if (! menu) showTip($el)"
                    x-on:blur="hideTip()"
                >
                    <span class="rg-admin-avatar rg-admin-avatar--lg" aria-hidden="true">{{ $account['initials'] }}</span>
                    <span class="rg-admin-account__text">
                        <span class="rg-admin-account__name">{{ $account['name'] }}</span>
                        @if ($account['role'] !== null)
                            <span class="rg-admin-account__role">{{ $account['role'] }}</span>
                        @endif
                    </span>
                    <x-admin.ui.icon name="chevrons-up-down" class="rg-admin-account__chevron" />
                    <span class="rg-admin-sr-only">Account menu</span>
                </button>

                <div id="rg-admin-account-menu" class="rg-admin-account-menu" x-cloak x-show="menu">
                    <div class="rg-admin-account-menu__identity">
                        <span class="rg-admin-account-menu__name">{{ $account['name'] }}</span>
                        <span class="rg-admin-account-menu__email">{{ $account['email'] }}</span>
                    </div>
                    <div class="rg-admin-menu__separator" aria-hidden="true"></div>
                    <form method="POST" action="{{ $logoutUrl }}">
                        @csrf
                        <button type="submit" class="rg-admin-account-menu__item">
                            <x-admin.ui.icon name="log-out" />
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
        @endif
    </aside>
</div>
