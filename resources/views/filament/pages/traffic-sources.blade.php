@php
    /*
     * Growth → Traffic sources (TrafficSourcesPage): the header band with the
     * period, the filters every tab reads — type of traffic, source,
     * campaign, game, device — the warnings that matter on every tab —
     * nothing recorded, no IP database — the tabs, and the tab on screen. Its
     * figures are read once the page is in a browser (wire:init).
     */
    use App\Filament\Pages\TrafficSourcesPage;

    $money = fn ($amount, int $decimals = 2): string => \App\Support\Traffic\Money\TrafficMoney::format($amount, $decimals);
    $percent = fn (?float $rate): string => $rate === null ? '—' : number_format($rate * 100, 1).'%';
@endphp

<div class="rg-admin rg-admin-screen rg-admin-traffic" wire:init="load">
    {{-- The header band holds the title, the period, the filters every tab reads and the tabs, as the reference draws it. --}}
    <section class="rg-admin-page-header rg-admin-traffic__header" aria-labelledby="rg-admin-traffic-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-traffic-title" class="rg-admin-page-header__title">Traffic sources</h1>
            <p class="rg-admin-page-header__description">Where visitors come from, what they cost and what they bring. {{ $periodLabel }}, by each network’s own day.</p>
        </div>
        <div class="rg-admin-traffic__filters">
            <x-admin.ui.filter-dropdown label="Period" id="rg-admin-traffic-period" :options="$periodOptions" :value="$period" wire:model.live="period" />
        </div>

        <div class="rg-admin-traffic__filter-bar" data-testid="traffic-filters">
            <x-admin.ui.filter-dropdown label="Traffic" id="rg-admin-traffic-type" :options="$typeOptions" :value="$type" wire:model.live="type" />
            <x-admin.ui.filter-dropdown label="Source" id="rg-admin-traffic-source" :options="$sourceOptions" :value="$source" wire:model.live="source" />
            <x-admin.ui.filter-dropdown label="Campaign" id="rg-admin-traffic-campaign" :options="$campaignOptions" :value="$campaignId" wire:model.live="campaignId" />
            <x-admin.ui.filter-dropdown label="Game" id="rg-admin-traffic-game" :options="$gameOptions" :value="$game" wire:model.live="game" />
            <x-admin.ui.filter-dropdown label="Device" id="rg-admin-traffic-device" :options="$deviceOptions" :value="$device" wire:model.live="device" />
        </div>

        <x-admin.ui.tabs label="Traffic sources" class="rg-admin-traffic__tabs" :active="$tab" :items="$tabs" />
    </section>

    <div class="rg-admin-screen__body">
        @if (! $capture)
            <x-admin.ui.inline-notice tone="warning">Visits aren’t recorded yet. Switch on “Record visits” under Sources → Tracking when you’re ready.</x-admin.ui.inline-notice>
        @endif
        @if ($geo['serving'] === null)
            <x-admin.ui.inline-notice tone="warning">
                No IP database yet: visits have no country or city until one is fetched.
                <x-slot:actions><x-admin.ui.button size="sm" icon="refresh-cw" wire:click="refreshGeo">Fetch it now</x-admin.ui.button></x-slot:actions>
            </x-admin.ui.inline-notice>
        @endif

        @if (! $ready)
            <div class="rg-admin-traffic__loading" aria-busy="true">
                <x-admin.ui.skeleton height="72px" shape="box" />
                <x-admin.ui.skeleton height="240px" shape="box" />
            </div>
        @else
            @include('filament.pages.traffic-sources.'.$tab)
        @endif
    </div>

    @if ($presetCards !== null)
        @include('filament.pages.traffic-sources.add-source')
    @endif
    @if ($editor !== null)
        @include('filament.pages.traffic-sources.source-editor')
    @endif
    @if ($campaign !== null)
        @include('filament.pages.traffic-sources.campaign-editor')
    @endif
</div>
