<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $appliedTheme }}" data-theme-preference="{{ $themePreference }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ strip_tags($__env->yieldContent('title', $projectSettings->siteName())) }}</title>

        @stack('meta')

        <script>{!! file_get_contents(resource_path('js/theme-bootstrap.js')) !!}</script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-rg-bg font-sans text-rg-text antialiased">
        <div class="min-h-screen">
            <header class="sticky top-0 z-40 border-b border-rg-border bg-rg-topbar" data-testid="app-header">
                <div class="mx-auto flex h-[60px] w-full max-w-[1440px] items-center gap-5 px-5 md:grid md:grid-cols-[minmax(0,1fr)_minmax(280px,520px)_minmax(0,1fr)]">
                    <a href="{{ url('/') }}" class="shrink-0 rounded-rgControl text-[22px] font-extrabold tracking-normal text-rg-text transition-colors hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg" data-testid="site-brand">
                        {{ $projectSettings->siteName() }}
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
                            aria-label="Search tags, users, posts"
                            placeholder="Search tags, users, posts..."
                            x-on:input.debounce.450ms="if ($el.value.length === 0 || $el.value.length >= 3) $el.form.requestSubmit()"
                            x-on:search="$el.form.requestSubmit()"
                            class="rg-search-input h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                        >
                    </form>

                    @auth
                        <div
                            x-data="{ open: false }"
                            @keydown.escape.window="open = false"
                            @post-uploaded.window="open = false"
                            class="ml-auto flex shrink-0 items-center justify-end gap-3 md:ml-0 md:justify-self-end"
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

                            <x-locale-switcher />

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
                                        aria-label="Open user menu"
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
                                    class="absolute right-0 z-50 mt-2 min-w-48 origin-top-right rounded-rgCard border border-rg-border bg-rg-card p-1 text-sm text-rg-text shadow-rgPopover ring-1 ring-rg-borderSoft"
                                    style="display: none;"
                                >
                                    <a
                                        href="{{ $profileHref }}"
                                        data-testid="header-profile-link"
                                        class="block rounded-rgSm px-3 py-2 text-sm font-medium text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text"
                                    >
                                        Profile
                                    </a>

                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button
                                            type="submit"
                                            class="block w-full cursor-pointer rounded-rgSm px-3 py-2 text-left text-sm font-medium text-rg-text2 transition hover:bg-rg-card2 hover:text-rg-text"
                                        >
                                            Log out
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
                                    {{ __('Sign up') }}
                                </a>
                            @endif

                            <a
                                href="{{ route('login') }}"
                                data-testid="header-login-link"
                                class="inline-flex h-[38px] cursor-pointer items-center justify-center gap-2 rounded-rgControl border border-rg-border2 bg-rg-card px-4 text-[13px] font-semibold text-rg-text2 transition-colors hover:bg-rg-card2 hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                            >
                                Log in
                            </a>

                            <x-locale-switcher />
                        </div>
                    @endauth
                </div>
            </header>

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
    </body>
</html>
