{{--
    A game, played (PlayGame): its own bar — the site's name and the way out;
    the page draws no header of its own — then the step on screen, or why the
    game can't be played now, or the end card. wire:init assigns the visitor
    once the browser runs the page, so a crawler is neither assigned nor
    counted.
--}}
@php($game = $play->data['game'])
<div @class(['rg-game', 'rg-game--fit' => ($play->data['type'] ?? null) === 'game']) wire:init="init" data-testid="game-page" data-game-state="{{ $play->state }}" data-step="{{ $play->data['stepKey'] ?? '' }}">
    {{-- Inside the root element: Livewire roots the component on the first element of this view. --}}
    @section('title', (empty($preview) ? '' : 'Preview · ').$play->pageTitle.' · '.$play->data['game']['site'])
    @if (empty($preview))
        {{-- The game's link, shared: its title, description and cover — or the site's own picture. --}}
        @php($ogImageUrl = $game['ogImage']?->url ?? asset(config('share.open_graph.fallback_path')))
        @push('meta')
            <link rel="canonical" href="{{ $game['url'] }}">
            <meta property="og:type" content="website">
            <meta property="og:title" content="{{ $game['title'] }}">
            <meta property="og:site_name" content="{{ $game['site'] }}">
            <meta property="og:url" content="{{ $game['url'] }}">
            <meta property="og:image" content="{{ $ogImageUrl }}">
            @if ($game['ogImage']?->width !== null)
                <meta property="og:image:width" content="{{ $game['ogImage']->width }}">
                <meta property="og:image:height" content="{{ $game['ogImage']->height }}">
            @endif
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $game['title'] }}">
            <meta name="twitter:image" content="{{ $ogImageUrl }}">
            @if ($game['description'] !== '')
                <meta name="description" content="{{ $game['description'] }}">
                <meta property="og:description" content="{{ $game['description'] }}">
                <meta name="twitter:description" content="{{ $game['description'] }}">
            @endif
        @endpush
    @endif
    @if (! empty($preview) && ($banner ?? true))
        <p class="rg-game__preview" role="status" data-testid="game-preview-banner">{{ __('games.play.preview_banner', ['source' => $preview]) }}</p>
    @endif

    <header class="rg-game__bar">
        <a href="{{ url('/') }}" class="rg-game__brand" data-testid="game-brand"><x-brand-wordmark /></a>
        <button type="button" class="rg-game__link" wire:click="leave('feed')" data-testid="game-skip-to-feed">{{ __('games.play.skip_to_feed') }} →</button>
    </header>

    <div class="rg-game__frame">
        @if ($play->state === 'unavailable')
            <section class="rg-game__card rg-game__card--center" data-testid="game-unavailable">
                <span class="rg-game__tile" aria-hidden="true"><x-ui.icon name="flag" class="size-6" /></span>
                <h1 class="rg-game__title rg-game__title--sm">{{ __('games.play.'.(in_array($play->data['reason'], ['paused', 'ended'], true) ? $play->data['reason'] : 'unavailable')) }}</h1>
                <button type="button" class="rg-game__button" wire:click="leave('feed')">{{ __('games.play.go_to_feed') }}</button>
            </section>
        @elseif ($play->state === 'end')
            <section class="rg-game__card rg-game__card--center" data-testid="game-end">
                <span class="rg-game__tile" aria-hidden="true"><x-ui.icon name="flag" class="size-6" /></span>
                <h1 class="rg-game__title rg-game__title--sm">{{ __('games.play.end_title') }}</h1>
                <p class="rg-game__lead">{{ __('games.play.end_text') }}</p>
                <div class="rg-game__actions">
                    @if ($play->data['canReplay'])
                        <button type="button" class="rg-game__button rg-game__button--primary" wire:click="replay" data-testid="game-replay">{{ __('games.play.play_again') }}</button>
                    @endif
                    <button type="button" class="rg-game__button" wire:click="leave('feed')">{{ __('games.play.go_to_feed') }}</button>
                </div>
            </section>
        @else
            @include('livewire.games.steps.'.str_replace('_', '-', $play->data['type']), ['data' => $play->data, 'texts' => $play->data['texts'], 'game' => $game])
        @endif
    </div>
</div>
