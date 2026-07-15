<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $appliedTheme }}" data-theme-preference="{{ $themePreference }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $projectSettings->siteName() }}</title>

        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">

        <script>{!! file_get_contents(resource_path('js/theme-bootstrap.js')) !!}</script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-rg-bg font-sans text-rg-text antialiased">
        <main class="flex min-h-screen items-center justify-center px-4 py-10 sm:px-6" data-screenshot="auth-page">
            <section class="w-full max-w-md">
                <div class="mb-8 flex justify-center">
                    <a href="{{ url('/') }}" class="rounded-rgControl px-2 py-1 text-[22px] font-extrabold tracking-tight text-rg-text transition-colors hover:text-rg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg">
                        <x-brand-wordmark />
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
