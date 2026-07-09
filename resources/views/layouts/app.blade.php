<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $appliedTheme }}" data-theme-preference="{{ $themePreference }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ strip_tags($__env->yieldContent('title', $projectSettings->siteName())) }}</title>

        @stack('meta')

        <script>{!! file_get_contents(resource_path('js/theme-bootstrap.js')) !!}</script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-rg-bg font-sans text-rg-text antialiased">
        <div class="min-h-screen" x-data="{ mobileNavOpen: false, mobileSearchOpen: false }">
            <header class="sticky top-0 z-40 border-b border-rg-border bg-rg-topbar" data-testid="app-header">
                <div class="mx-auto flex h-[60px] w-full max-w-[1440px] items-center gap-1.5 px-3 sm:gap-2 sm:px-4 md:gap-4 md:px-5 md:grid md:grid-cols-[auto_1fr_minmax(0,480px)_auto] lg:grid-cols-[1fr_minmax(0,480px)_auto]">
                    <button
                        type="button"
                        class="grid size-9 shrink-0 cursor-pointer place-items-center rounded-rgControl border border-rg-border2 bg-rg-card text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent lg:hidden"
                        aria-label="{{ __('ui.nav.open_menu') }}"
                        data-testid="mobile-nav-trigger"
                        x-on:click="mobileNavOpen = true"
                    >
                        <x-ui.icon name="menu" class="size-5" />
                    </button>

                    <a href="{{ url('/') }}" class="min-w-0 shrink self-center rounded-rgControl px-1 py-1 text-[17px] font-extrabold tracking-normal text-rg-text transition-colors hover:text-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg sm:px-2 sm:text-[22px]" data-testid="site-brand">
                        <span class="block truncate">{{ $projectSettings->siteName() }}</span>
                    </a>

                    <form
                        action="{{ route('feed') }}"
                        method="GET"
                        data-testid="app-header-search"
                        x-data
                        class="relative hidden w-full max-w-[520px] justify-self-center md:block"
                    >
                        <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
                        <input
                            type="search"
                            name="search"
                            value="{{ request('search') }}"
                            aria-label="{{ __('ui.feed.search_label') }}"
                            placeholder="{{ __('ui.feed.search_placeholder') }}"
                            x-on:input.debounce.450ms="if ($el.value.length === 0 || $el.value.length >= 3) $el.form.requestSubmit()"
                            x-on:search="$el.form.requestSubmit()"
                            class="rg-search-input h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                        >
                    </form>

                    <button
                        type="button"
                        class="ml-auto grid size-9 shrink-0 cursor-pointer place-items-center rounded-rgControl border border-rg-border2 bg-rg-card text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent md:hidden"
                        aria-label="{{ __('ui.feed.search_label') }}"
                        data-testid="mobile-search-trigger"
                        x-on:click="mobileSearchOpen = ! mobileSearchOpen"
                        x-bind:aria-expanded="mobileSearchOpen"
                    >
                        <x-ui.icon name="search" class="size-4" />
                    </button>

                    @auth
                        <div
                            x-data="{ open: false }"
                            @keydown.escape.window="open = false"
                            @post-uploaded.window="open = false"
                            class="ml-auto flex shrink-0 items-center justify-end gap-2 md:ml-0 md:gap-3 md:justify-self-end"
                        >
                            @if($projectSettings->featureFlag('allow_user_uploads'))
                            <x-ui.button
                                data-testid="open-upload-button"
                                x-on:click="open = true; $dispatch('upload-modal-opened')"
                                elevated
                            >
                                <x-ui.icon name="upload" class="size-4" />
                                <span class="hidden sm:inline">{{ $projectSettings->uploadCtaLabel() }}</span>
                            </x-ui.button>
                            @endif

                            <livewire:notifications.notification-bell />

                            <div class="hidden md:block">
                                <x-locale-switcher />
                            </div>

                            <div
                                x-data="{ userMenuOpen: false }"
                                class="relative"
                                @click.outside="userMenuOpen = false"
                                @keydown.escape.window="userMenuOpen = false"
                                @close-header-user-menu.window="userMenuOpen = false"
                            >
                                <button
                                    type="button"
                                    data-testid="header-user-menu-trigger"
                                    aria-label="{{ __('ui.a11y.open_user_menu') }}"
                                    aria-haspopup="true"
                                    :aria-expanded="userMenuOpen"
                                    @click="$dispatch('close-notification-menu'); userMenuOpen = ! userMenuOpen"
                                    class="cursor-pointer rounded-full transition hover:ring-2 hover:ring-rg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                                >
                                    <x-ui.avatar
                                        :src="auth()->user()->avatar_url"
                                        :name="auth()->user()->name ?: auth()->user()->username"
                                        color="purple"
                                        size="lg"
                                    />
                                </button>

                                <div
                                    x-cloak
                                    x-show="userMenuOpen"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 scale-95"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-95"
                                    data-testid="header-user-menu"
                                    class="absolute right-0 z-50 mt-2 w-52 origin-top-right rounded-rgCard border border-rg-border bg-rg-card p-1 text-sm text-rg-text shadow-rgPopover ring-1 ring-rg-borderSoft"
                                    style="display: none;"
                                >
                                    <div class="px-3 py-2">
                                        <p class="mb-2 text-xs font-medium text-rg-muted">{{ __('ui.theme') }}</p>
                                        <livewire:theme.theme-switcher layout="dropdown" />
                                    </div>

                                    <div class="my-1 border-t border-rg-border"></div>

                                    <a
                                        href="{{ $profileHref }}"
                                        data-testid="header-profile-link"
                                        class="flex items-center gap-2 rounded-rgSm px-3 py-2 text-sm font-medium text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text"
                                    >
                                        {{ __('ui.nav.profile') }}
                                    </a>

                                    @if($projectSettings->featureFlag('show_saved_posts'))
                                    <a
                                        href="{{ route('saved-posts.index') }}"
                                        data-testid="nav-saved-posts"
                                        class="flex items-center gap-2 rounded-rgSm px-3 py-2 text-sm font-medium text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text"
                                    >
                                        {{ __('saved_posts.saved_posts') }}
                                    </a>
                                    @endif

                                    <div class="my-1 border-t border-rg-border"></div>

                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="flex w-full cursor-pointer items-center gap-2 rounded-rgSm px-3 py-2 text-left text-sm font-medium text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text"
                                        >
                                            {{ __('ui.nav.log_out') }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            @if($projectSettings->featureFlag('allow_user_uploads'))
                            <div>
                                <x-ui.modal :title="$projectSettings->uploadCtaLabel()" size="lg" data-testid="upload-modal" allow-overflow>
                                    <livewire:feed.upload-post-form />
                                </x-ui.modal>
                            </div>
                            @endif
                        </div>
                    @else
                        <div class="ml-auto flex shrink-0 items-center justify-end gap-2 md:ml-0 md:justify-self-end">
                            @if (Route::has('register'))
                                <a
                                    href="{{ route('register') }}"
                                    data-testid="header-register-link"
                                    class="inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl bg-rg-accent px-4 text-[13px] font-semibold text-rg-onAccent transition-colors hover:bg-rg-accentHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                                >
                                    {{ __('ui.nav.sign_up') }}
                                </a>
                            @endif

                            <a
                                href="{{ route('login') }}"
                                data-testid="header-login-link"
                                class="inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                            >
                                {{ __('ui.nav.log_in') }}
                            </a>

                            <div class="hidden md:block">
                                <x-locale-switcher />
                            </div>

                            <livewire:theme.theme-switcher />
                        </div>
                    @endauth
                </div>

                {{-- Mobile search row --}}
                <div
                    x-cloak
                    x-show="mobileSearchOpen"
                    x-on:keydown.escape.window="mobileSearchOpen = false"
                    class="border-t border-rg-border px-4 py-2 md:hidden"
                    data-testid="mobile-search-row"
                >
                    <form action="{{ route('feed') }}" method="GET" class="relative">
                        <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
                        <input
                            type="search"
                            name="search"
                            value="{{ request('search') }}"
                            aria-label="{{ __('ui.feed.search_label') }}"
                            placeholder="{{ __('ui.feed.search_placeholder') }}"
                            x-effect="if (mobileSearchOpen) setTimeout(() => $el.focus(), 60)"
                            x-on:search="$el.form.requestSubmit()"
                            class="rg-search-input h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                        >
                    </form>
                </div>
            </header>

            {{-- Mobile navigation drawer --}}
            <div
                x-cloak
                x-show="mobileNavOpen"
                x-on:keydown.escape.window="mobileNavOpen = false"
                class="fixed inset-0 z-50 lg:hidden"
                role="dialog"
                aria-modal="true"
                aria-label="{{ __('ui.nav.open_menu') }}"
                data-testid="mobile-nav-drawer"
            >
                <div
                    class="fixed inset-0 bg-rg-overlay backdrop-blur-sm"
                    x-on:click="mobileNavOpen = false"
                    x-transition:enter="transition ease-out duration-150"
                    x-transition:enter-start="opacity-0"
                    x-transition:enter-end="opacity-100"
                    x-transition:leave="transition ease-in duration-100"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                ></div>

                <div
                    class="fixed inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col overflow-y-auto border-r border-rg-border bg-rg-sidebar px-4 py-4"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="-translate-x-full"
                    x-transition:enter-end="translate-x-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="translate-x-0"
                    x-transition:leave-end="-translate-x-full"
                >
                    <div class="mb-4 flex items-center justify-between gap-2">
                        <a
                            href="{{ url('/') }}"
                            class="min-w-0 truncate text-[20px] font-extrabold tracking-normal text-rg-text transition-colors hover:text-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                            data-testid="mobile-nav-brand"
                        >
                            {{ $projectSettings->siteName() }}
                        </a>
                        <div class="flex shrink-0 items-center gap-2">
                            <x-locale-switcher />
                            <button
                                type="button"
                                class="grid size-8 cursor-pointer place-items-center rounded-rgSm border border-rg-border2 bg-rg-card2 text-rg-text2 transition hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent"
                                aria-label="{{ __('ui.nav.close_menu') }}"
                                data-testid="mobile-nav-close"
                                x-on:click="mobileNavOpen = false"
                            >
                                <x-ui.icon name="x" class="size-4" />
                            </button>
                        </div>
                    </div>

                    <form action="{{ route('feed') }}" method="GET" class="relative mb-5" data-testid="mobile-nav-search">
                        <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
                        <input
                            type="search"
                            name="search"
                            value="{{ request('search') }}"
                            aria-label="{{ __('ui.feed.search_label') }}"
                            placeholder="{{ __('ui.feed.search_placeholder') }}"
                            class="rg-search-input h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                        >
                    </form>

                    @include('layouts.partials.app-sidebar-content')
                </div>
            </div>

            @if($projectSettings->featureFlag('post_detail_overlay_mode', false))
                {{-- Global post detail overlay: shared across the feed, profile, and saved-posts
                     pages so clicking a post always opens the same sliding panel. The backdrop
                     has no Livewire-driven content, so plain window-event Alpine state is safe
                     and reliable here. The sliding <aside> is rendered by PostDrawer itself
                     (asOverlay=true) — see that view for why its content needs a different,
                     Blade-driven approach. --}}
                <div
                    x-data="{ open: false }"
                    x-cloak
                    x-on:select-post.window="open = true"
                    x-on:request-close-overlay.window="open = false"
                    {{-- Deletion (see PostDrawer::deleteSelectedPost) closes via clear-selected-post
                         directly, bypassing request-close-overlay, so the backdrop needs its own
                         listener here too or it stays visible/dimmed after the panel is gone. --}}
                    x-on:clear-selected-post.window="open = false"
                    data-testid="post-detail-overlay-backdrop-root"
                    class="pointer-events-none fixed inset-x-0 top-[60px] bottom-0 z-40"
                >
                    {{-- Bounded to the site's own max-w-[1440px] mx-auto container (not the
                         raw viewport) so the outer margins stay undimmed on wide screens —
                         otherwise there was no visible line between "outside the site" and
                         "dimmed site content," and the panel looked like it was sliding in
                         from the bare browser edge instead of the site's edge. --}}
                    <div class="relative mx-auto h-full w-full max-w-[1440px]">
                        {{-- border-r marks the site's edge with a hard, always-crisp line for the
                             entire time the backdrop is up (not just at rest) — the color contrast
                             between dimmed/undimmed alone was too soft to read as a fixed boundary
                             during the ~200ms slide, making the panel feel like it came from
                             somewhere off in the margin rather than from this exact line. --}}
                        <div
                            x-show="open"
                            x-on:click="$dispatch('request-close-overlay')"
                            x-transition:enter="motion-safe:transition-opacity motion-reduce:transition-none ease-out duration-200"
                            x-transition:enter-start="opacity-0"
                            x-transition:enter-end="opacity-100"
                            x-transition:leave="motion-safe:transition-opacity motion-reduce:transition-none ease-in duration-200"
                            x-transition:leave-start="opacity-100"
                            x-transition:leave-end="opacity-0"
                            class="pointer-events-auto absolute inset-0 border-r border-rg-border2 bg-rg-overlay"
                            data-testid="post-detail-overlay-backdrop"
                            aria-hidden="true"
                        ></div>
                    </div>
                </div>

                <livewire:feed.post-drawer wire:key="global-post-detail-overlay-drawer" :as-overlay="true" />
            @endif

            <div class="mx-auto grid w-full max-w-[1440px] lg:grid-cols-[240px_minmax(0,1fr)]">
                @include('layouts.partials.app-sidebar')

                <div class="min-w-0">
                    @isset($header)
                        <section class="border-b border-rg-border bg-rg-surface">
                            <div class="px-4 py-6 sm:px-6 lg:px-8">
                                {{ $header }}
                            </div>
                        </section>
                    @endisset

                    <main class="{{ $isFeedRoute ? 'px-4 py-6 sm:px-6 lg:px-6' : 'px-4 py-10 sm:px-6 lg:px-8' }}">
                        {{ $slot ?? '' }}
                        @yield('content')
                    </main>
                </div>
            </div>
        </div>

        {{-- Global toast notifications --}}
        <div
            x-data="{ toasts: [] }"
            x-on:toast.window="
                const toast = { id: Date.now() + Math.random(), message: $event.detail.message };
                toasts.push(toast);
                setTimeout(() => toasts = toasts.filter(t => t.id !== toast.id), 5000);
            "
            class="pointer-events-none fixed inset-x-0 bottom-4 z-[70] flex flex-col items-center gap-2 px-4"
            data-testid="toast-container"
            aria-live="polite"
        >
            <template x-for="toast in toasts" :key="toast.id">
                <div
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-2"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    class="pointer-events-auto max-w-md rounded-rgCard border border-rg-border bg-rg-card px-4 py-2.5 text-center text-sm font-medium text-rg-text shadow-rgPopover"
                    x-text="toast.message"
                ></div>
            </template>
        </div>
    </body>
</html>
