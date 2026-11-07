@extends('layouts.app')

@section('title', __('ui.errors.not_found_title'))

@section('content')
    <div class="mx-auto flex min-h-[50vh] max-w-md flex-col items-center justify-center text-center" data-testid="error-404">
        <p class="text-6xl font-extrabold text-rg-accent">404</p>
        <h1 class="mt-4 text-xl font-bold text-rg-text">{{ __('ui.errors.not_found_title') }}</h1>
        <p class="mt-2 text-sm text-rg-muted">{{ __('ui.errors.not_found_description') }}</p>
        <a
            href="{{ url('/') }}"
            class="mt-6 inline-flex h-[38px] items-center justify-center gap-2 rounded-rgControl bg-rg-accent px-4 text-[13px] font-semibold text-rg-onAccent transition-colors hover:bg-rg-accentHover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rg-accent focus-visible:ring-offset-2 focus-visible:ring-offset-rg-bg"
        >
            {{ __('ui.errors.back_home') }}
        </a>
    </div>
@endsection
