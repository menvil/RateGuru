<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', config('app.name', 'RateGuru'))</title>

        @stack('meta')

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-zinc-950 font-sans text-zinc-100 antialiased">
        <div class="min-h-screen">
            <header class="border-b border-white/10 bg-zinc-950/90">
                <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
                    <a href="{{ url('/') }}" class="flex items-center gap-3">
                        <span class="flex size-9 items-center justify-center rounded bg-amber-400 font-semibold text-zinc-950">
                            RG
                        </span>
                        <span class="text-lg font-semibold tracking-normal text-white">RateGuru</span>
                    </a>

                    @auth
                        <div
                            x-data="{ open: false }"
                            @keydown.escape.window="open = false"
                            @post-uploaded.window="open = false"
                            class="flex items-center gap-4"
                        >
                            <button
                                type="button"
                                x-on:click="open = true; $dispatch('upload-modal-opened')"
                                class="inline-flex items-center justify-center gap-2 rounded-rgControl border border-transparent bg-rg-accent px-4 text-[13px] font-semibold text-white transition-colors hover:bg-rg-accentHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg h-[38px]"
                            >
                                + Create post
                            </button>

                            <div data-testid="upload-modal">
                                <x-ui.modal title="Create post" size="lg">
                                    <livewire:feed.upload-post-form />
                                </x-ui.modal>
                            </div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="text-sm font-medium text-zinc-300 hover:text-white">
                                    {{ __('Log out') }}
                                </button>
                            </form>
                        </div>
                    @endauth
                </div>
            </header>

            @isset($header)
                <section class="border-b border-white/10 bg-zinc-900/40">
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
