{{--
    A shared game result (GameResultController), under the game's own bar and
    drawn as the game's Full Result is: the profile's description large, then
    the card it went out as — the game, the profile, and the player's @name on
    a player's own result or the site's address on a profile's —
    as the version it was played in froze it, and the game to play. Its
    preview elsewhere shows the profile's share image or the game's cover —
    never a post's picture.
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
        <header class="rg-game__bar">
            <a href="{{ url('/') }}" class="rg-game__brand" data-testid="game-brand"><x-brand-wordmark /></a>
            <a href="{{ route('feed') }}" class="rg-game__link">{{ __('games.play.go_to_feed') }} →</a>
        </header>
        <div class="rg-game__frame">
            <article class="rg-game__card">
                @if ($profile['description'] !== '')
                    <p @class(['rg-game__result-headline', 'rg-game__result-headline--long' => mb_strlen($profile['description']) > 80]) data-testid="game-profile-description">{{ $profile['description'] }}</p>
                @endif
                <div class="rg-game__share-card">
                    @if ($image)
                        <img src="{{ $image }}" alt="" class="rg-game__share-card-image">
                    @else
                        <span class="rg-game__share-card-glow" aria-hidden="true"><x-ui.icon :name="$profile['icon'] ?: 'award'" class="size-16" /></span>
                    @endif
                    <span class="rg-game__share-card-shade" aria-hidden="true"></span>
                    <div class="rg-game__share-card-text">
                        <span class="rg-game__share-card-game">{{ $gameTitle }}</span>
                        <h1 class="rg-game__share-card-profile">{{ $profile['name'] }}</h1>
                        <span class="rg-game__share-card-line" data-testid="game-result-byline">{{ $byline }}</span>
                    </div>
                </div>
                @if ($over)
                    <p class="rg-game__note">{{ __('games.result.over') }}</p>
                @else
                    <a href="{{ $playUrl }}" class="rg-game__button rg-game__button--primary rg-game__button--wide" data-testid="game-result-play">{{ __('games.result.play') }} <span aria-hidden="true">→</span></a>
                @endif
            </article>
        </div>
    </div>
</x-app-layout>
