@php
    /*
     * The media confirmations (OVL-01): releasing an asset nothing uses
     * (warning), replacing an asset's generated variants (warning), and
     * purging an asset past its grace period (danger, irreversible — the
     * callout, what happens and an acknowledgement). The lifecycle checks
     * again when each is confirmed.
     */
    $action = $confirmation['action'];
    $id = $confirmation['assetId'];

    [$tone, $icon, $title, $body, $confirm] = match ($action) {
        'release' => ['warning', 'archive', 'Release asset #'.$id.'?', 'The asset is active, but nothing references it. Releasing hands it to the media lifecycle.', 'Release asset'],
        'force' => ['warning', 'refresh-cw', 'Replace the variants of asset #'.$id.'?', 'Its generated variants are made again from the master and replace the existing ones.', 'Force regenerate'],
        default => ['danger', 'trash-2', 'Purge asset #'.$id.' permanently?', 'This deletes the master file and its variants from storage.', 'Purge permanently'],
    };
    $facts = match ($action) {
        'release' => [
            ['search', 'Checked again when you confirm: an asset something uses now is left as it is.'],
            ['clock', 'After '.$confirmation['graceDays'].' '.\Illuminate\Support\Str::plural('day', $confirmation['graceDays']).' it becomes purgeable.'],
        ],
        'force' => [
            ['image', 'The normalized master is not modified.'],
            ['layers', 'Existing generated variants are replaced.'],
        ],
        default => [
            ['files', 'Deletes the master, its '.$confirmation['variants'].' '.\Illuminate\Support\Str::plural('variant', $confirmation['variants']).' and the asset’s record.'],
            ['lock', 'Proceeds only if the asset is still purgeable: the grace period, its references and the purge lock are checked again.'],
        ],
    };
    $waiting = $action === 'purge' ? '! $wire.acknowledged' : 'false';
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-media-confirm"
    :tone="$tone"
    :icon="$icon"
    :title="$title"
    wire:key="media-confirm-{{ $action }}-{{ $id }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    <p>{{ $body }}</p>

    <x-slot:details>
        <div class="rg-admin-dialog-subject">
            <span class="rg-admin-dialog-subject__icon" aria-hidden="true"><x-admin.ui.icon name="image" :size="16" /></span>
            <span class="rg-admin-dialog-subject__text">
                <span class="rg-admin-dialog-subject__title">Asset #{{ $id }} · {{ $confirmation['size'] }}</span>
                <span class="rg-admin-dialog-subject__meta">{{ $confirmation['path'] ?? '—' }}</span>
            </span>
        </div>

        @if ($action === 'purge')
            <x-admin.ui.inline-notice tone="danger">This can’t be undone. The files are deleted from storage, not moved.</x-admin.ui.inline-notice>
        @endif

        <div>
            <div class="rg-admin-section-label"><h3 class="rg-admin-section-label__text">What happens</h3></div>
            <ul class="rg-admin-dialog-facts">
                @foreach ($facts as [$factIcon, $fact])
                    <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon :name="$factIcon" :size="16" />{{ $fact }}</li>
                @endforeach
            </ul>
        </div>

        @if ($action === 'purge')
            <div>
                <x-admin.ui.checkbox label="I understand these files are deleted permanently." id="rg-admin-media-acknowledge" wire:model="acknowledged" />
                @error('acknowledged')
                    <p class="rg-admin-error"><x-admin.ui.icon name="circle-alert" :size="12" />{{ $message }}</p>
                @enderror
            </div>
        @endif
    </x-slot:details>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        <x-admin.ui.button :variant="$action === 'purge' ? 'danger' : 'primary'" wire:click="confirm" wire:loading.attr="disabled" x-bind:disabled="{{ $waiting }}">{{ $confirm }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
