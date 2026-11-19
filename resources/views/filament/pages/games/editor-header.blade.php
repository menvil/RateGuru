@php
    /*
     * The header band every tab of a game's editor shares: the game's icon,
     * title and status, what it is (mechanic, address, internal name — each
     * behind its icon), its status menu — each move asked first, a move that
     * cannot be made now saying why — and the editor's tabs (NAV-03). The
     * tabs are links: each is a page. Under them, on a tab whose saved changes
     * the players don't see yet, what changed — named while there are three at
     * most, else the first three and how many more, with every change behind a
     * disclosure — what they play instead and the way to put the changes live.
     */
    $game = $header['game'];
@endphp

<section class="rg-admin-page-header rg-admin-games__header" aria-labelledby="rg-admin-game-title">
    <div class="rg-admin-games__heading">
        <span class="rg-admin-games__glyph rg-admin-games__glyph--lg" title="{{ $game['mechanic'] }}"><x-admin.ui.icon :name="$game['icon']" :size="20" /></span>
        <div class="rg-admin-page-header__text">
            <div class="rg-admin-games__title-row">
                <h1 id="rg-admin-game-title" class="rg-admin-page-header__title">{{ $game['title'] }}</h1>
                <x-admin.ui.badge :tone="$game['status']['tone']" :dot="$game['status']['dot']">{{ $game['status']['label'] }}</x-admin.ui.badge>
            </div>
            <p class="rg-admin-page-header__description rg-admin-games__meta">
                <span class="rg-admin-games__meta-item"><x-admin.ui.icon :name="$game['icon']" :size="14" />{{ $game['mechanic'] }}</span>
                <span class="rg-admin-games__meta-item"><x-admin.ui.icon name="link" :size="14" /><span class="rg-admin-mono">/games/{{ $game['slug'] }}</span></span>
                <span class="rg-admin-games__meta-item"><x-admin.ui.icon name="tag" :size="14" />{{ $game['internal'] }}</span>
            </p>
        </div>
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

@if (($header['unpublished'] ?? null) !== null)
    @php($unpublished = $header['unpublished'])
    {{-- Saved, but not what the players play: what changed, and the way to put it live. --}}
    <x-admin.ui.inline-notice tone="warning" strip class="rg-admin-games__unpublished" data-testid="game-unpublished">
        <div class="rg-admin-games__unpublished-body">
            <p data-testid="game-unpublished-what"><strong>{{ $unpublished['headline'] }}:</strong> {{ $unpublished['named'] }}</p>
            <p>Players don’t see these saved changes yet. They play {{ $unpublished['versions'] }}, published before them. Publish {{ $unpublished['next'] }} to put them live; it takes over {{ $unpublished['plural'] ? 'the traffic of the newest' : 'that version’s traffic' }}.</p>
            @if ($unpublished['groups'] !== [])
                @include('filament.pages.games.unpublished-changes-list', ['count' => $unpublished['count'], 'groups' => $unpublished['groups'], 'testid' => 'game-unpublished-all'])
            @endif
        </div>
        <x-slot:actions>
            <x-admin.ui.button size="sm" variant="primary" icon="upload" :href="$unpublished['url']">Publish {{ $unpublished['next'] }}…</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

@if ($header['confirmingStatus'] !== null)
    @include('filament.pages.games.status-confirmation', ['confirmation' => $header['confirmingStatus']])
@endif
