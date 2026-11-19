@php
    /*
     * The filter bar of a game's analytics (GameAnalyticsView), as the
     * reference draws it on the game's Analytics tab and under Growth →
     * Analytics → Games: the games to choose from where the bar picks one,
     * the period picker where the page has its own, the versions, the
     * audiences with their visitors, the device where the page has its own,
     * the country, then the days compared and Step prices — and, under it,
     * the word that only plays on recorded visits count while a device or
     * country is chosen.
     *
     * $gameChoices is the games to choose from — each with its id, title and
     * whether it is the one shown — or null; $periodChoice the period
     * picker's data (ChoosesReportPeriod::periodPicker()) or null;
     * $deviceChoice the device menu's options or null. Every other variable
     * is the view model's.
     */
@endphp

<div class="rg-admin-games__analytics-filters" data-testid="game-analytics-filters">
    @if ($gameChoices !== null)
        {{-- The games on the segmented track: as many as there are, wrapping onto more lines when the bar is full. --}}
        <div class="rg-admin-segmented rg-admin-segmented--compact rg-admin-games__game-choices" role="radiogroup" aria-label="Game" data-testid="game-analytics-games">
            @foreach ($gameChoices as $choice)
                <button type="button" role="radio" class="rg-admin-segmented__option" aria-checked="{{ $choice['chosen'] ? 'true' : 'false' }}" wire:click="$set('game', '{{ $choice['id'] }}')" wire:key="game-analytics-game-{{ $choice['id'] }}" data-game="{{ $choice['id'] }}">{{ $choice['title'] }}</button>
            @endforeach
        </div>
    @endif
    @if ($periodChoice !== null)
        @include('filament.pages.elements.period-picker', ['picker' => $periodChoice, 'id' => 'rg-admin-games-analytics-period'])
    @endif
    @if (! $noVersions)
        {{-- Keyed by the game: another game's versions are another menu, its names read afresh. --}}
        <x-admin.ui.filter-dropdown label="Versions" id="rg-admin-games-analytics-version" :options="$versionOptions" :value="(string) $version" wire:model.live="version" wire:key="game-analytics-version-{{ $gameId }}" />
    @endif
    {{-- The audiences with their visitors over the period, on the segmented control's track. --}}
    <div class="rg-admin-segmented rg-admin-segmented--compact rg-admin-games__audiences" role="radiogroup" aria-label="Group">
        @foreach ($audiences as $key => $label)
            <button type="button" role="radio" class="rg-admin-segmented__option" aria-checked="{{ $audience === $key ? 'true' : 'false' }}" wire:click="$set('audience', @js($key))">
                <span>{{ $label }}</span>
                <span class="rg-admin-games__audience-count">{{ $audienceCounts[$key] }}</span>
            </button>
        @endforeach
    </div>
    @if ($deviceChoice !== null)
        <x-admin.ui.filter-dropdown label="Device" id="rg-admin-games-analytics-device" :options="$deviceChoice" :value="$device" wire:model.live="device" />
    @endif
    <x-admin.ui.filter-dropdown label="Country" id="rg-admin-games-analytics-country" class="rg-admin-games__country" :options="$countryOptions" :value="$country" wire:model.live="country" wire:key="game-analytics-country-{{ $gameId }}-{{ count($countryOptions) }}" />
    {{-- Pushes the dates and Step prices right while the bar is one line; wrapped, they start a line of their own on the left, as the reference has. --}}
    <span class="rg-admin-games__analytics-spacer" aria-hidden="true"></span>
    <div class="rg-admin-games__analytics-scope">
        <span class="rg-admin-games__note" role="status">{{ $datesText }}</span>
        <x-admin.ui.button size="sm" icon="circle-dollar-sign" :href="$valueUrl">Step prices</x-admin.ui.button>
    </div>
</div>
@if ($visitNote !== null)
    <p class="rg-admin-games__note rg-admin-games__visit-note" data-testid="game-analytics-visit-note"><x-admin.ui.icon name="info" :size="14" />{{ $visitNote }}</p>
@endif
