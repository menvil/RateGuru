@php
    /*
     * Analytics → Games (GamesTab), as the reference draws it: a game's own
     * analytics, drawn by the partials of its Analytics tab — the filter bar
     * with the games to choose from in place of the period picker and the
     * device, which are the page's, then every card under it. No game yet
     * says so, with the way to Growth → Games.
     */
@endphp

@if ($data['empty'])
    <x-admin.ui.card>
        <x-admin.ui.empty-state icon="chart-no-axes-column" title="No games yet" data-testid="analytics-games-empty">
            A game’s figures appear here once it is made in Growth → Games: how far its visitors get, what they are worth and who signs up.
            <x-slot:action>
                <x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$data['gamesUrl']">Open Games</x-admin.ui.button>
            </x-slot:action>
        </x-admin.ui.empty-state>
    </x-admin.ui.card>
@else
    @include('filament.pages.games.analytics.filters', [...$data, 'periodChoice' => null, 'deviceChoice' => null])
    @include('filament.pages.games.analytics.cards', $data)
@endif
