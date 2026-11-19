@php
    /*
     * A traffic period's results in Growth → Experiments, as the reference
     * draws them: the header band — the game and audience, whether the
     * period is still open, whether its versions can be compared yet, its
     * days and visitors, Delete period for an ended one and the way to the
     * game's Traffic — then the periods of the same game and audience to
     * switch to, a hidden period's strip, what the figures say so far, every
     * version's rates against the reference version (TBL-01, one grid that
     * scrolls sideways inside its card), and the ways to the game's Versions
     * and Analytics.
     */
    $columns = '--rg-admin-table-columns: minmax(200px, 1.4fr) 64px 88px repeat('.count($heads ?? []).', minmax(120px, 1fr)); --rg-admin-table-min-width: '.(352 + 120 * count($heads ?? [])).'px';
@endphp

@if ($notFound)
    <section class="rg-admin-page-header rg-admin-experiments__header" aria-labelledby="rg-admin-experiments-period-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-experiments-period-title" class="rg-admin-page-header__title">Period not found</h1>
        </div>
    </section>

    <div class="rg-admin-screen__body">
        <x-admin.ui.inline-notice tone="info">This period doesn’t exist any more.</x-admin.ui.inline-notice>
    </div>
@else
    <section class="rg-admin-page-header rg-admin-experiments__header" aria-labelledby="rg-admin-experiments-period-title">
        <div class="rg-admin-page-header__text rg-admin-experiments__heading">
            <div class="rg-admin-experiments__title-row">
                <h1 id="rg-admin-experiments-period-title" class="rg-admin-page-header__title">{{ $title }}</h1>
                <x-admin.ui.badge :tone="$ended ? 'neutral' : 'success'" :dot="! $ended">{{ $ended ? 'Ended' : 'Current' }}</x-admin.ui.badge>
                <x-admin.ui.badge :tone="$state['tone']">{{ $state['label'] }}</x-admin.ui.badge>
            </div>
            <p class="rg-admin-page-header__description">{{ $meta }}</p>
        </div>
    </section>

    <div class="rg-admin-screen__body">
        @if ($switcher !== [])
            <nav class="rg-admin-experiments__switcher" aria-label="Periods for {{ $group }}">
                <span class="rg-admin-experiments__switcher-label">Periods for {{ $group }}</span>
                @foreach ($switcher as $each)
                    <a href="{{ $each['url'] }}" class="rg-admin-experiments__period" @if ($each['current']) aria-current="page" @endif>
                        <span class="rg-admin-experiments__period-dates">{{ $each['label'] }}</span>
                        <span class="rg-admin-experiments__period-mix">{{ $each['mix'] }}</span>
                        @if ($each['scope'] !== null)
                            <span class="rg-admin-experiments__period-scope">{{ $each['scope'] }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>
        @endif

        @if ($hidden)
            <x-admin.ui.inline-notice tone="info" icon="eye-off" data-testid="experiments-hidden">
                Hidden from Experiments.
                <x-slot:actions>
                    <x-admin.ui.button size="sm" wire:click="showPeriod">Show in Experiments</x-admin.ui.button>
                </x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        <x-admin.ui.inline-notice :tone="$note['tone']" :icon="$note['icon']" data-testid="experiments-note">{{ $note['text'] }}</x-admin.ui.inline-notice>

        <div class="rg-admin-table rg-admin-experiments__versions">
            <div class="rg-admin-table__scroll">
                <div class="rg-admin-table__grid" role="table" aria-label="Versions compared" style="{{ $columns }}">
                    <div class="rg-admin-table__row rg-admin-table__row--head rg-admin-experiments__versions-head" role="row">
                        <div class="rg-admin-table__cell" role="columnheader">Version</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Share</div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Visitors</div>
                        @foreach ($heads as $head)
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $head }}</div>
                        @endforeach
                    </div>

                    @foreach ($rows as $row)
                        <div class="rg-admin-table__row rg-admin-experiments__version-row" role="row" wire:key="experiments-version-{{ $row['label'] }}">
                            <div class="rg-admin-table__cell rg-admin-table__cell--padded" role="cell">
                                <div class="rg-admin-experiments__version">
                                    <span class="rg-admin-experiments__version-label">{{ $row['label'] }}</span>
                                    @if ($row['reference'])
                                        <x-admin.ui.badge tone="outline">Reference</x-admin.ui.badge>
                                    @endif
                                </div>
                                <span class="rg-admin-table__meta rg-admin-table__meta--small rg-admin-table__ellipsis">{{ $row['combo'] }}</span>
                            </div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figure" role="cell">{{ $row['share'] }}</div>
                            <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__figure" role="cell">{{ $row['visitors'] }}</div>
                            @foreach ($row['cells'] as $cell)
                                <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-experiments__metric" role="cell">
                                    <span @class(['rg-admin-experiments__rate', 'rg-admin-experiments__rate--none' => $cell['dashed']])>{{ $cell['rate'] }}</span>
                                    <span @class(['rg-admin-experiments__sub', 'rg-admin-experiments__sub--'.$cell['tone'] => $cell['tone'] !== null]) @if ($cell['evidence'] !== null) title="{{ $cell['evidence'] }}" @endif>{{ $cell['sub'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
            <p class="rg-admin-experiments__foot">{{ $foot }}</p>
        </div>

        <div class="rg-admin-experiments__links">
            <x-admin.ui.button size="sm" icon="history" :href="$versionsUrl">Versions</x-admin.ui.button>
            <x-admin.ui.button size="sm" icon="chart-no-axes-column" :href="$analyticsUrl">Analytics</x-admin.ui.button>
        </div>
    </div>
@endif
