{{--
    A shared game result (GameResultController): the profile as the version it
    was played in froze it, and the game to play. Its preview elsewhere shows
    the profile's share image or the game's cover — never a post's picture.
--}}
@php
    $ogUrl = $ogImage?->url ?? asset(config('share.open_graph.fallback_path'));
@endphp
<x-app-layout :focused="true">
    @section('title', $shareTitle)

    @push('meta')
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ $shareTitle }}">
        <meta property="og:site_name" content="{{ $site }}">
        <meta property="og:description" content="{{ $profile['description'] }}">
        <meta property="og:url" content="{{ $url }}">
        <meta property="og:image" content="{{ $ogUrl }}">
        @if ($ogImage?->width !== null)
            <meta property="og:image:width" content="{{ $ogImage->width }}">
        @endif
        @if ($ogImage?->height !== null)
            <meta property="og:image:height" content="{{ $ogImage->height }}">
        @endif
        <meta name="description" content="{{ $profile['description'] }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle }}">
        <meta name="twitter:description" content="{{ $profile['description'] }}">
        <meta name="twitter:image" content="{{ $ogUrl }}">
    @endpush

    <div class="rg-game" data-testid="game-result-page">
        <div class="rg-game__frame">
            <article class="rg-game__card rg-game__card--result">
                @if ($image)
                    <img src="{{ $image }}" alt="" class="rg-game__result-image">
                @endif
                <p class="rg-game__eyebrow">{{ $gameTitle }}</p>
                <h1 class="rg-game__title">{{ $profile['name'] }}</h1>
                @if ($profile['description'] !== '')
                    <p class="rg-game__lead">{{ $profile['description'] }}</p>
                @endif
                @if ($over)
                    <p class="rg-game__note">{{ __('games.result.over') }}</p>
                @else
                    <a href="{{ $playUrl }}" class="rg-game__button rg-game__button--primary" data-testid="game-result-play">{{ __('games.result.play') }}</a>
                @endif
            </article>
        </div>
    </div>
</x-app-layout>
