@php
    use App\Filament\Pages\Analytics\GeographyTab;

    /*
     * Analytics → Geography (GeographyTab), as the reference draws it: how a
     * place is known and the credit of the IP database placing visitors,
     * then the countries and cities — a country a row with its flag, its
     * people, their share of everyone drawn as a bar against the widest row,
     * and the three rates; the open country's cities under it on a sunken
     * band, the rest of the country last; then every other country together
     * and those no database could place. A click on a country opens it, or
     * closes it when open. The table scrolls sideways inside its card where
     * the card is narrower. While no IP database serves, the tab says so
     * first, with the way to the setting that fetches one; nobody matching
     * the filters is said once, with no table drawn.
     */
    $place = $data['place'];
@endphp

@if (! $place['serving'])
    <x-admin.ui.inline-notice tone="warning" data-testid="analytics-geo-no-database">
        No IP database yet: visits have no country or city until one is fetched.
        <x-slot:actions><x-admin.ui.button size="sm" trailing-icon="arrow-right" :href="$place['tracking']">Open Tracking</x-admin.ui.button></x-slot:actions>
    </x-admin.ui.inline-notice>
@endif

@if ($data['empty'])
    @include('filament.pages.analytics.elements.no-visitors', ['range' => $data['range'], 'filtered' => $data['filtered']])
@else
    <x-admin.ui.inline-notice tone="info" icon="map-pin" data-testid="analytics-geo-banner">
        Country and city come from the IP address at each visit and are approximate. The raw IP isn’t stored. Cities with fewer than {{ $place['least'] }} visitors are grouped into “Other cities” so single people can’t be picked out.
        @if ($place['credit']['url'] !== null)
            <a href="{{ $place['credit']['url'] }}" target="_blank" rel="noopener" class="rg-admin-analytics__geo-credit">{{ $place['credit']['text'] }}</a>
        @else
            {{ $place['credit']['text'] }}
        @endif
    </x-admin.ui.inline-notice>

    <x-admin.ui.card title="Countries and cities" :description="$data['note']" flush class="rg-admin-analytics__geo-card" data-testid="analytics-geo">
        <x-slot:aside>
            <x-admin.ui.segmented label="Sort" compact :options="GeographyTab::SORTS" :value="$data['sort']" wire:model.live="gsort" class="rg-admin-analytics__sort" />
        </x-slot:aside>
        <div class="rg-admin-table__scroll">
            <div class="rg-admin-table__grid rg-admin-analytics__geo" role="table" aria-label="Countries and cities">
                <div class="rg-admin-table__row rg-admin-table__row--head" role="row">
                    <div class="rg-admin-table__cell rg-admin-analytics__geo-place" role="columnheader">Country · city</div>
                    <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">Visitors</div>
                    <div class="rg-admin-table__cell" role="columnheader">Share</div>
                    @foreach (['Start rate', 'Registration', 'Activation'] as $column)
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric" role="columnheader">{{ $column }}</div>
                    @endforeach
                </div>
                @foreach ($data['rows'] as $row)
                    <div
                        @class([
                            'rg-admin-table__row rg-admin-analytics__geo-row',
                            'rg-admin-analytics__geo-row--'.$row['kind'],
                            'rg-admin-analytics__geo-row--inner' => in_array($row['kind'], ['city', 'others'], true),
                        ])
                        role="row"
                        data-testid="analytics-geo-row"
                        data-kind="{{ $row['kind'] }}"
                        wire:key="analytics-geo-{{ $row['key'] }}"
                        @if ($row['toggle'] !== null) wire:click="$set('open', @js($row['toggle']))" @endif
                    >
                        <div class="rg-admin-table__cell rg-admin-analytics__geo-place" role="cell">
                            <span class="rg-admin-analytics__geo-chevron" aria-hidden="true">
                                @if ($row['kind'] === 'country')
                                    <x-admin.ui.icon :name="$row['open'] ? 'chevron-down' : 'chevron-right'" :size="14" />
                                @endif
                            </span>
                            <span class="rg-admin-analytics__geo-flag" aria-hidden="true">{{ $row['flag'] }}</span>
                            @if ($row['kind'] === 'country')
                                <button type="button" class="rg-admin-analytics__geo-name" aria-expanded="{{ $row['open'] ? 'true' : 'false' }}" title="{{ $row['name'] }}">{{ $row['name'] }}</button>
                            @else
                                <span class="rg-admin-analytics__geo-name" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                            @endif
                        </div>
                        <div class="rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__geo-visitors" role="cell">{{ $row['visitors'] }}</div>
                        <div class="rg-admin-table__cell rg-admin-analytics__geo-share" role="cell">
                            <span class="rg-admin-analytics__geo-track"><span class="rg-admin-analytics__geo-bar" style="--rg-admin-analytics-geo-bar: {{ $row['bar'] }}%"></span></span>
                            <span class="rg-admin-analytics__geo-percent">{{ $row['share'] }}</span>
                        </div>
                        @foreach ($row['rates'] as $rate)
                            <div @class(['rg-admin-table__cell rg-admin-table__cell--numeric rg-admin-analytics__geo-rate', 'rg-admin-analytics__geo-rate--none' => $rate['quiet']]) role="cell" @if ($rate['title'] !== null) title="{{ $rate['title'] }}" @endif>{{ $rate['value'] }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
        <x-slot:footer data-testid="analytics-geo-foot">{{ $data['foot'] }}</x-slot:footer>
    </x-admin.ui.card>
@endif
