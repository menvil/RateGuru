<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ strip_tags($__env->yieldContent('title', config('app.name', 'RateGuru'))) }}</title>

        @stack('meta')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-rg-bg font-sans text-rg-text antialiased">
        <div class="min-h-screen">
            <header class="flex h-[60px] items-center gap-5 border-b border-rg-border bg-rg-topbar px-5" data-testid="app-header">
                <a href="{{ url('/') }}" class="shrink-0 rounded-rgControl text-[22px] font-extrabold tracking-normal text-rg-text transition-colors hover:bg-rg-card hover:text-rg-text focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg">
                    Rate<span class="text-rg-accent2">Guru</span>
                </a>

                <form
                    action="{{ route('feed') }}"
                    method="GET"
                    data-testid="app-header-search"
                    class="relative hidden max-w-[520px] flex-1 md:block"
                >
                    <x-ui.icon name="search" class="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-rg-muted" />
                    <input
                        type="search"
                        name="search"
                        value="{{ request('search') }}"
                        aria-label="Search tags, users, dishes"
                        placeholder="Search tags, users, dishes..."
                        class="h-10 w-full rounded-rgControl border border-rg-border bg-rg-card py-0 pl-10 pr-3 text-[13.5px] text-rg-text placeholder:text-rg-muted focus-visible:border-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent/25"
                    >
                </form>

                @auth
                    @php
                        $headerUser = auth()->user();
                        $profileHref = filled($headerUser->username)
                            ? route('profile.show', ['username' => $headerUser->username])
                            : route('profile.edit');
                    @endphp

                    <div
                        x-data="{ open: false }"
                        @keydown.escape.window="open = false"
                        @post-uploaded.window="open = false"
                        class="ml-auto flex shrink-0 items-center justify-end gap-3"
                    >
                        <x-ui.button
                            data-testid="open-upload-button"
                            x-on:click="open = true; $dispatch('upload-modal-opened')"
                            elevated
                        >
                            <x-ui.icon name="upload" class="size-4" />
                            <span class="hidden sm:inline">Upload</span>
                        </x-ui.button>

                        <livewire:notifications.notification-bell />

                        <a
                            href="{{ $profileHref }}"
                            data-testid="header-profile-link"
                            aria-label="Open profile"
                            class="rounded-full transition hover:ring-2 hover:ring-rg-accent/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
                        >
                            <x-ui.avatar
                                :src="$headerUser->avatar_url"
                                :name="$headerUser->name ?: $headerUser->username"
                                color="purple"
                                size="lg"
                            />
                        </a>

                        <div data-testid="upload-modal">
                            <x-ui.modal title="Create post" size="lg">
                                <livewire:feed.upload-post-form />
                            </x-ui.modal>
                        </div>
                    </div>
                @endauth
            </header>

            @isset($header)
                <section class="border-b border-rg-border bg-rg-surface">
                    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </section>
            @endisset

            <main class="mx-auto max-w-6xl px-4 py-10 sm:px-6 lg:px-8">
                {{ $slot ?? '' }}
                @yield('content')
            </main>
        </div>
    </body>
</html>
