@php
    /*
     * Growth → Experiments (ExperimentsPage), as the reference draws it: the
     * list of traffic periods, or — with a period in the address — that
     * period's results. The figures are read once the page is in a browser
     * (wire:init); a period's Delete period and Open Traffic sit in the top
     * bar (ExperimentsPage::topbar).
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-experiments" x-data wire:init="load">
    @if (! $ready)
        <div class="rg-admin-screen__body">
            <div class="rg-admin-experiments__loading" aria-busy="true">
                <x-admin.ui.skeleton height="72px" shape="box" />
                <x-admin.ui.skeleton height="320px" shape="box" />
            </div>
        </div>
    @elseif ($showsPeriod)
        @include('filament.pages.experiments.period')
    @else
        @include('filament.pages.experiments.list')
    @endif
</div>
