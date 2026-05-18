<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'RateGuru') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-rg-bg font-sans text-rg-text antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
            <section class="w-full max-w-md">
                <div class="mb-8 flex justify-center">
                    <a href="{{ url('/') }}" class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded bg-rg-accent font-semibold text-rg-onAccent">
                            RG
                        </span>
                        <span class="text-xl font-semibold tracking-normal text-rg-text">RateGuru</span>
                    </a>
                </div>

                <div class="rounded border border-rg-border bg-rg-card p-6 shadow-xl shadow-black/20 sm:p-8">
                    {{ $slot ?? '' }}
                    @yield('content')
                </div>
            </section>
        </main>
    </body>
</html>
