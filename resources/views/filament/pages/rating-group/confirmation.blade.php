@php
    /*
     * The options' confirmations (OVL-01). Archive warns and names the
     * option; Delete is a danger confirmation for an option never voted on.
     * The rest explain: an option with votes can only be archived, a group at
     * its minimum cannot spare an active option, and one at its maximum
     * takes another only if the maximum rises — which the dialog offers in
     * one step while the group may allow one more.
     */
    $label = $row['label'];
    $votes = $row['votes'];
    $votesText = number_format($votes).' '.\Illuminate\Support\Str::plural('vote', $votes);
    $subjectMeta = $row['key'].' · '.($row['active'] ? 'Active' : ($row['archived'] !== null ? 'Archived' : 'Inactive'));
    $canRaise = $active + 1 <= \App\Models\RatingGroup::MOST_OPTIONS;
@endphp

@if ($confirming === 'archive')
    <x-admin.ui.confirm-dialog id="rg-admin-rating-group-confirm" tone="warning" icon="archive" :title="'Archive '.$label.'?'" wire:key="confirm-archive-option-{{ $row['id'] }}" x-on:dismiss="$wire.closeConfirmation()">
        <p>Voters stop seeing this option. Its {{ $votesText }} {{ $votes === 1 ? 'stays' : 'stay' }} in results and vote history.</p>

        <x-slot:details>
            <div class="rg-admin-dialog-subject">
                <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="star" :size="16" /></span>
                <span class="rg-admin-dialog-subject__text">
                    <span class="rg-admin-dialog-subject__title">{{ $label }}</span>
                    <span class="rg-admin-dialog-subject__meta">{{ $subjectMeta }}</span>
                </span>
            </div>
        </x-slot:details>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="archiveOption({{ $row['id'] }})" wire:loading.attr="disabled">Archive option</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($confirming === 'delete')
    <x-admin.ui.confirm-dialog id="rg-admin-rating-group-confirm" tone="danger" icon="trash-2" :title="'Delete '.$label.'?'" wire:key="confirm-delete-option-{{ $row['id'] }}" x-on:dismiss="$wire.closeConfirmation()">
        <p>This option has never been voted on. It is deleted with its translations.</p>

        <x-slot:details>
            <div class="rg-admin-dialog-subject">
                <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="star" :size="16" /></span>
                <span class="rg-admin-dialog-subject__text">
                    <span class="rg-admin-dialog-subject__title">{{ $label }}</span>
                    <span class="rg-admin-dialog-subject__meta">{{ $row['key'] }} · 0 votes</span>
                </span>
            </div>
        </x-slot:details>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="danger" wire:click="deleteOption({{ $row['id'] }})" wire:loading.attr="disabled">Delete option</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($confirming === 'voted')
    <x-admin.ui.confirm-dialog id="rg-admin-rating-group-confirm" tone="warning" icon="archive" :title="$label.' has vote history'" wire:key="confirm-voted-option-{{ $row['id'] }}" x-on:dismiss="$wire.closeConfirmation()">
        <p>{{ $votesText }} {{ $votes === 1 ? 'references' : 'reference' }} this option. Deleting it would rewrite past results, so it can only be archived.</p>
        @if ($row['archived'] !== null)
            <p>It is already archived.</p>
        @endif

        <x-slot:actions>
            @if ($row['archived'] !== null)
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
            @else
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="askToArchive({{ $row['id'] }})">Archive instead</x-admin.ui.button>
            @endif
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($confirming === 'below-min')
    <x-admin.ui.confirm-dialog id="rg-admin-rating-group-confirm" tone="warning" :title="$label.' can’t be '.$blockedAs" wire:key="confirm-below-min-option-{{ $row['id'] }}" x-on:dismiss="$wire.closeConfirmation()">
        <p>{{ $group->label }} needs at least {{ $group->min_options }} active options and has exactly {{ $active }}. Activate another option first, or lower the minimum.</p>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($confirming === 'above-max')
    <x-admin.ui.confirm-dialog id="rg-admin-rating-group-confirm" tone="warning" :title="$label.' can’t be activated'" wire:key="confirm-above-max-option-{{ $row['id'] }}" x-on:dismiss="$wire.closeConfirmation()">
        <p>{{ $group->label }} already has {{ $active }} active options, the maximum allowed.</p>

        <x-slot:details>
            <div>
                <div class="rg-admin-section-label">
                    <h3 class="rg-admin-section-label__text">To activate it</h3>
                </div>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="minus" :size="16" />Deactivate or archive another option first{{ $canRaise ? ', or' : '.' }}</li>
                    @if ($canRaise)
                        <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="plus" :size="16" />raise the maximum to {{ $active + 1 }}. Voters will see one more choice.</li>
                    @endif
                </ul>
            </div>
        </x-slot:details>

        <x-slot:actions>
            @if ($canRaise)
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Keep inactive</x-admin.ui.button>
                <x-admin.ui.button variant="primary" wire:click="raiseMaximumAndActivate({{ $row['id'] }})" wire:loading.attr="disabled">Raise maximum to {{ $active + 1 }} and activate</x-admin.ui.button>
            @else
                <x-admin.ui.button x-on:click="dismiss()" autofocus>Close</x-admin.ui.button>
            @endif
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@endif
