{{-- A period's results, in the top bar as the reference has them: Delete period while the period can be deleted, and the way to the game's Traffic. ExperimentsPage listens for the event. --}}
@if ($canDelete)
    <x-admin.ui.button icon="trash-2" x-on:click="$dispatch('delete-experiment-period')">Delete period</x-admin.ui.button>
@endif
<x-admin.ui.button icon="route" :href="$trafficUrl">Open Traffic</x-admin.ui.button>
