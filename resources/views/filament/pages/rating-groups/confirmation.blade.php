@php
    /*
     * The list's confirmations (OVL-01). Deactivating warns that voters stop
     * seeing the group and that its votes stay. Activating a group outside
     * its range cannot happen: the dialog says what it lacks and opens its
     * editor, with nothing to confirm.
     */
    $label = $row['label'];
    $active = $row['activeOptions'];
@endphp

@if ($confirming === 'deactivate')
    <x-admin.ui.confirm-dialog
        id="rg-admin-rating-groups-confirm"
        tone="warning"
        icon="eye-off"
        :title="'Deactivate '.$label.'?'"
        wire:key="confirm-deactivate-rating-group-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>Voters stop seeing this group on posts. Its {{ number_format($row['votes']) }} {{ \Illuminate\Support\Str::plural('vote', $row['votes']) }} stay stored and come back if you activate it again.</p>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="deactivate({{ $row['id'] }})" wire:loading.attr="disabled">Deactivate group</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($confirming === 'blocked')
    <x-admin.ui.confirm-dialog
        id="rg-admin-rating-groups-confirm"
        tone="warning"
        :title="$label.' can’t be activated yet'"
        wire:key="confirm-blocked-rating-group-{{ $row['id'] }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>An active group must have between {{ $row['min'] }} and {{ $row['max'] }} active options. {{ $label }} has {{ number_format($active) }}.</p>

        <x-slot:details>
            <div>
                <div class="rg-admin-section-label">
                    <h3 class="rg-admin-section-label__text">To activate it</h3>
                </div>
                <ul class="rg-admin-dialog-facts">
                    @if ($active < $row['min'])
                        @php($missing = $row['min'] - $active)
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="plus" :size="16" />Activate or create {{ $missing }} more {{ \Illuminate\Support\Str::plural('option', $missing) }}{{ $active >= \App\Models\RatingGroup::FEWEST_OPTIONS ? ', or' : '.' }}</li>
                        @if ($active >= \App\Models\RatingGroup::FEWEST_OPTIONS)
                            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="minus" :size="16" />lower the minimum to {{ $active }} in the group settings.</li>
                        @endif
                    @else
                        @php($extra = $active - $row['max'])
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="minus" :size="16" />Deactivate or archive {{ $extra }} {{ \Illuminate\Support\Str::plural('option', $extra) }}{{ $active <= \App\Models\RatingGroup::MOST_OPTIONS ? ', or' : '.' }}</li>
                        @if ($active <= \App\Models\RatingGroup::MOST_OPTIONS)
                            <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="plus" :size="16" />raise the maximum to {{ $active }} in the group settings.</li>
                        @endif
                    @endif
                </ul>
            </div>
        </x-slot:details>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()">Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" :href="$row['url']" autofocus>Open {{ $label }}</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@endif
