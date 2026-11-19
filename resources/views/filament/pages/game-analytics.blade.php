@php
    /*
     * A game's Analytics tab (GameAnalytics), as the reference draws it: the
     * editor's header band, then the game's analytics — the filter bar with
     * the period picker Traffic sources shares and the page's own device,
     * then everything under it — drawn by the partials Growth → Analytics →
     * Games draws them with.
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-games" x-data>
    @include('filament.pages.games.editor-header', ['header' => $header])

    <div class="rg-admin-screen__body">
        @include('filament.pages.games.analytics.filters', ['gameChoices' => null, 'periodChoice' => $periodPicker, 'deviceChoice' => $deviceOptions])
        @include('filament.pages.games.analytics.cards')
    </div>
</div>
