@extends('layouts.app')

@section('title', $title)

@section('content')
    <article
        class="mx-auto max-w-[820px]"
        data-testid="static-page"
        data-page="{{ $pageKey }}"
    >
        <p class="text-sm font-medium uppercase tracking-normal text-rg-accent2">
            {{ $siteName }}
        </p>

        <h1 class="mt-3 text-3xl font-semibold text-rg-text sm:text-4xl">
            {{ $title }}
        </h1>

        <x-ui.card variant="panel" class="mt-6">
            <div class="whitespace-pre-line text-sm leading-7 text-rg-text2 sm:text-base">
                {{ $content }}
            </div>
        </x-ui.card>
    </article>
@endsection
