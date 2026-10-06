@php
    /*
     * The confirmation for one language (OVL-01). Enabling with complete
     * project content is a light confirmation; enabling with content missing
     * and disabling are warnings. Nothing asks for a reason: no reason for a
     * language change is stored anywhere, so asking for one would be for show.
     */
    $row = $confirmation['row'];
    $code = $row['code'];
    $label = $row['label'];
@endphp

@if ($confirmation['action'] === 'disable')
    <x-admin.ui.confirm-dialog
        id="rg-admin-languages-confirm"
        tone="warning"
        icon="globe"
        :title="'Disable '.$label.'?'"
        wire:key="confirm-disable-{{ $code }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>Visitors currently using {{ $label }} will get their browser's language if it is enabled, otherwise {{ $defaultLabel }}.</p>
        <p>Their {{ $label }} preference is kept and will apply again if {{ $label }} is enabled later.</p>
        <p>Stored {{ $label }} translations are not deleted.</p>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="disableLanguage('{{ $code }}')" wire:loading.attr="disabled">Disable {{ $label }}</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@elseif ($row['missing'] > 0)
    <x-admin.ui.confirm-dialog
        id="rg-admin-languages-confirm"
        tone="warning"
        icon="globe"
        :title="'Enable '.$label.'?'"
        wire:key="confirm-enable-{{ $code }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>
            {{ $label }} has {{ number_format($row['missing']) }} missing project {{ \Illuminate\Support\Str::plural('translation', $row['missing']) }}.
            Visitors may see {{ $referenceLabel }} fallback content.
        </p>

        <x-slot:actions>
            {{-- The drawer replaces the dialog; nothing is enabled. --}}
            <x-admin.ui.button x-on:click="hide()" wire:click="showMissing('{{ $code }}')">Review missing</x-admin.ui.button>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="enableLanguage('{{ $code }}')" wire:loading.attr="disabled">Enable anyway</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@else
    <x-admin.ui.confirm-dialog
        id="rg-admin-languages-confirm"
        icon="globe"
        :title="'Enable '.$label.'?'"
        wire:key="confirm-enable-{{ $code }}"
        x-on:dismiss="$wire.closeConfirmation()"
    >
        <p>{{ $label }} has complete project translations and will become available to visitors.</p>

        <x-slot:actions>
            <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
            <x-admin.ui.button variant="primary" wire:click="enableLanguage('{{ $code }}')" wire:loading.attr="disabled">Enable</x-admin.ui.button>
        </x-slot:actions>
    </x-admin.ui.confirm-dialog>
@endif
