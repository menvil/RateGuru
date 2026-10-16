@php
    /*
     * The comment confirmations (OVL-01): Hide is a warning with an optional
     * reason — the chips are the reasons visitors report a comment with —
     * and Finalize removal is irreversible: the red callout, what happens,
     * a required internal reason and an acknowledgement.
     */
    use App\Filament\Resources\Comments\Pages\ListComments;

    $finalizing = $confirmation['action'] === 'finalize';
    $moderator = auth()->user()?->name;
    // The confirm button waits for what the server will ask for anyway, as it is typed.
    $waiting = $finalizing ? "(\$wire.reason ?? '').trim().length < 3 || ! \$wire.acknowledged" : 'false';
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-comments-confirm"
    :tone="$finalizing ? 'danger' : 'warning'"
    :icon="$finalizing ? 'triangle-alert' : 'eye-off'"
    :title="$finalizing ? 'Finalize removal?' : 'Hide this comment?'"
    wire:key="confirm-{{ $confirmation['action'] }}-{{ $confirmation['id'] }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    @if ($finalizing)
        <p>Use this for comments that must never return to the thread.</p>
    @else
        <p>It disappears from the thread for everyone but moderators. Restore stays available until the removal is finalized.</p>
    @endif

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="message-square" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">{{ $confirmation['text'] }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $confirmation['meta'] }}</span>
            </span>
        </div>

        @if ($finalizing)
            <x-admin.ui.inline-notice tone="danger">This can’t be undone. After finalizing, no moderator or administrator can restore this comment.</x-admin.ui.inline-notice>

            <div>
                <div class="rg-admin-section-label">
                    <h3 class="rg-admin-section-label__text">What happens</h3>
                </div>
                <ul class="rg-admin-dialog-facts">
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="eye-off" :size="16" />The comment leaves the thread permanently, for its author too.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="lock" :size="16" />Restore is disabled for everyone.</li>
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="archive" :size="16" />An internal copy is kept under the moderation retention rules, then deleted.</li>
                </ul>
            </div>
        @endif

        @include('filament.pages.moderation.reason', [
            'label' => $finalizing ? 'Internal reason' : 'Moderation reason',
            'required' => $finalizing,
            'limit' => ListComments::REASON_LIMIT,
            'presets' => $finalizing ? [] : $reasons,
            'hint' => $finalizing ? 'Visible to administrators only. Not shared with the author.' : 'Stored in the moderation log.',
            'acknowledge' => $finalizing ? 'I understand this comment can no longer be restored.' : null,
        ])
    </x-slot:details>

    <x-slot:footnote>{{ $moderator !== null ? 'Recorded in the moderation log as '.$moderator.'.' : 'Recorded in the moderation log.' }}</x-slot:footnote>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" :autofocus="! $finalizing">Cancel</x-admin.ui.button>
        <x-admin.ui.button
            :variant="$finalizing ? 'danger' : 'primary'"
            wire:click="confirm"
            wire:loading.attr="disabled"
            x-bind:disabled="{{ $waiting }}"
        >{{ $finalizing ? 'Finalize removal' : 'Hide comment' }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
