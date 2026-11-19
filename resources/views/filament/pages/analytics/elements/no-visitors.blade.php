@php
    /*
     * What a tab that reads visits draws when nobody matches the filters —
     * one state, with no empty table or chart — and the way to clear the
     * device, source and campaign when any is chosen.
     *
     * @var string $range  the period's days
     * @var bool $filtered  whether a device, source or campaign is chosen
     */
@endphp

<x-admin.ui.card>
    <x-admin.ui.empty-state icon="search-x" title="No visitors for these filters" data-testid="analytics-empty">
        Nothing matches in {{ $range }}. Pick a longer range or clear the filters.
        @if ($filtered)
            <x-slot:action>
                <x-admin.ui.button size="sm" wire:click="clearFilters">Clear filters</x-admin.ui.button>
            </x-slot:action>
        @endif
    </x-admin.ui.empty-state>
</x-admin.ui.card>
