@php
    /*
     * The header band every tab of a game's editor shares: the game's title
     * and status, what it is (internal name, mechanic, address), its status
     * menu — each move asked first, a move that cannot be made now saying why
     * — and the editor's tabs (NAV-03). The tabs are links: each is a page.
     */
    $game = $header['game'];
@endphp

<section class="rg-admin-page-header rg-admin-games__header" aria-labelledby="rg-admin-game-title">
    <div class="rg-admin-page-header__text">
        <div class="rg-admin-games__title-row">
            <h1 id="rg-admin-game-title" class="rg-admin-page-header__title">{{ $game['title'] }}</h1>
            <x-admin.ui.badge :tone="$game['status']['tone']" :dot="$game['status']['dot']">{{ $game['status']['label'] }}</x-admin.ui.badge>
        </div>
        <p class="rg-admin-page-header__description rg-admin-games__meta">
            <span>{{ $game['internal'] }}</span>
            <span>· {{ $game['mechanic'] }}</span>
            <span>· <span class="rg-admin-mono">/games/{{ $game['slug'] }}</span></span>
        </p>
    </div>

    <x-admin.ui.row-menu id="rg-admin-game-status-menu" label="Change the game's status">
        <p class="rg-admin-menu__caption">Status · {{ $game['status']['label'] }}</p>
        @foreach ($header['moves'] as $move)
            <x-admin.ui.menu-item
                :icon="$move['icon']"
                :tone="$move['target'] === 'archived' ? 'danger' : 'default'"
                :disabled="$move['hint'] !== null"
                :hint="$move['hint']"
                wire:click="askStatus('{{ $move['target'] }}')"
            >{{ $move['label'] }}</x-admin.ui.menu-item>
        @endforeach
    </x-admin.ui.row-menu>

    <nav class="rg-admin-games__tabs" aria-label="Game editor">
        <ul class="rg-admin-tabs">
            @foreach ($header['tabs'] as $tab)
                <li>
                    <a href="{{ $tab['url'] }}" class="rg-admin-tab" @if ($tab['current']) aria-current="page" @endif>
                        {{ $tab['label'] }}
                        @if ($tab['badge'] !== null)
                            <span class="rg-admin-tab__count">{{ $tab['badge'] }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>
</section>

@if ($header['confirmingStatus'] !== null)
    @include('filament.pages.games.status-confirmation', ['confirmation' => $header['confirmingStatus']])
@endif
