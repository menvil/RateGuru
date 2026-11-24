@php
    /*
     * Growth → Analytics (GrowthAnalyticsPage), as the reference draws it:
     * the header band with the title, the period picker, the filters every
     * tab reads — device, source, campaign — with the chip of the source
     * chosen and the reminder that RateGuru counts every figure itself, and
     * the strip of the tabs built so far; then what the page says about what
     * is recorded, and the tab open. Its figures are read once the page is in
     * a browser (wire:init); the period's days sit in the top bar
     * (GrowthAnalyticsPage::topbar).
     */
@endphp

<div class="rg-admin rg-admin-screen rg-admin-analytics" wire:init="load">
    <section class="rg-admin-page-header rg-admin-analytics__header" aria-labelledby="rg-admin-analytics-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-analytics-title" class="rg-admin-page-header__title">Analytics</h1>
            <p class="rg-admin-page-header__description">Where visitors come from and what they do. {{ site_name() }} counts every figure here itself.</p>
        </div>
        <div class="rg-admin-analytics__period">
            @include('filament.pages.elements.period-picker', ['picker' => $periodPicker, 'id' => 'rg-admin-analytics-period', 'align' => 'end'])
        </div>

        <div class="rg-admin-analytics__filters" data-testid="analytics-filters">
            <x-admin.ui.filter-dropdown label="Device" id="rg-admin-analytics-device" :options="$deviceOptions" :value="$this->device" wire:model.live="device" />
            {{-- The sources the most people came from in the period, once the page is in a browser; until then the one chosen. --}}
            <x-admin.ui.filter-dropdown label="Source" id="rg-admin-analytics-source" class="rg-admin-analytics__filter" :options="$sourceOptions" :value="$this->source" wire:model.live="source" wire:key="analytics-source-{{ count($sourceOptions) }}-{{ $this->source }}" />
            <x-admin.ui.filter-dropdown label="Campaign" id="rg-admin-analytics-campaign" class="rg-admin-analytics__filter" :options="$campaignOptions" :value="$this->campaign" wire:model.live="campaign" wire:key="analytics-campaign-{{ count($campaignOptions) }}-{{ $this->source }}-{{ $this->campaign }}" />
            @if ($chip !== null)
                <span class="rg-admin-analytics__chip" data-testid="analytics-source-chip">
                    <span class="rg-admin-analytics__chip-text">Source: {{ $chip }}</span>
                    <button type="button" class="rg-admin-analytics__chip-remove" aria-label="Remove source filter" wire:click="clearSource">
                        <x-admin.ui.icon name="x" :size="12" />
                    </button>
                </span>
            @endif
            <span class="rg-admin-analytics__counted"><x-admin.ui.icon name="shield-check" :size="13" />Counted by {{ site_name() }}</span>
            {{-- A tab slow to read shows its figures as kept since they were read, for a few minutes, until Refresh. --}}
            @if ($readAt !== null)
                <span class="rg-admin-analytics__read" data-testid="analytics-figures-read">
                    Figures read {{ $readAt }} UTC
                    <x-admin.ui.button variant="ghost" size="sm" icon="refresh-cw" wire:click="refreshFigures" wire:loading.attr="disabled" wire:target="refreshFigures">Refresh</x-admin.ui.button>
                </span>
            @endif
        </div>

        <x-admin.ui.tabs label="Analytics" class="rg-admin-analytics__tabs" :active="$tab" :items="$tabs" />
    </section>

    <div class="rg-admin-screen__body">
        @foreach ($notices as $notice)
            <x-admin.ui.inline-notice :tone="$notice['tone']" data-testid="analytics-notice">
                {{ $notice['text'] }}
                @if ($notice['action'] !== null)
                    <x-slot:actions><x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$notice['action']['url']">{{ $notice['action']['label'] }}</x-admin.ui.button></x-slot:actions>
                @endif
            </x-admin.ui.inline-notice>
        @endforeach

        @if ($state === 'loading')
            <div class="rg-admin-analytics__loading" aria-busy="true" aria-label="Loading the figures">
                <x-admin.ui.skeleton height="200px" shape="box" />
                <div class="rg-admin-analytics__pair">
                    <x-admin.ui.skeleton height="380px" shape="box" />
                    <x-admin.ui.skeleton height="380px" shape="box" />
                </div>
                <x-admin.ui.skeleton height="480px" shape="box" />
            </div>
        @elseif ($state === 'nothing')
            <x-admin.ui.card>
                {{-- A switch that is off says so in the notice above, with the way to Tracking. --}}
                <x-admin.ui.empty-state icon="chart-no-axes-column" title="No visits recorded yet">
                    Analytics counts the visits {{ site_name() }} records itself. None has been recorded yet.
                </x-admin.ui.empty-state>
            </x-admin.ui.card>
        @elseif ($state === 'early')
            <x-admin.ui.card>
                <x-admin.ui.empty-state icon="calendar" title="Too early for these days">
                    Visits are recorded since {{ $since }}, after {{ $range }}. Pick a later period.
                </x-admin.ui.empty-state>
            </x-admin.ui.card>
        @else
            @include('filament.pages.analytics.'.$tab)
        @endif
    </div>
</div>
