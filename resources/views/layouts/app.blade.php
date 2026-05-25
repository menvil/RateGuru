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
            <header class="border-b border-rg-border bg-rg-topbar" data-testid="app-header">
                <div class="mx-auto flex min-h-[60px] max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                    <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-3 rounded-rgControl px-1 py-1 transition-colors hover:bg-rg-card hover:text-rg-text">
                        <span class="flex size-9 items-center justify-center rounded-rgSm border border-rg-border2 bg-rg-card2 font-semibold text-rg-text">
                            RG
                        </span>
                        <span class="truncate text-lg font-semibold tracking-normal text-rg-text">RateGuru</span>
                    </a>

                    @auth
                        <div
                            x-data="{ open: false }"
                            @keydown.escape.window="open = false"
                            @post-uploaded.window="open = false"
                            class="flex shrink-0 items-center justify-end gap-2 sm:gap-3"
                        >
                            <x-ui.button
                                data-testid="open-upload-button"
                                x-on:click="open = true; $dispatch('upload-modal-opened')"
                                elevated
                            >
                                <x-ui.icon name="upload" class="size-4" />
                                <span class="hidden sm:inline">Create post</span>
                                <span class="sm:hidden">Post</span>
                            </x-ui.button>

                            <livewire:notifications.notification-bell />

                            <div data-testid="upload-modal">
                                <x-ui.modal title="Create post" size="lg">
                                    <livewire:feed.upload-post-form />
                                </x-ui.modal>
                            </div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-sm font-medium text-rg-text2 hover:text-rg-text">
                                    {{ __('Log out') }}
                                </button>
                            </form>
                        </div>
                    @endauth
                </div>
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
