@php
    /*
     * Switching off what locks people out (OVL-01, warning): pausing uploads,
     * requiring sign-in for everyone, or a sign-in provider — whose accounts stay
     * linked. Each applies only when the settings are saved.
     */
    $title = match (true) {
        $confirmation['kind'] === 'provider' => 'Turn off '.$confirmation['label'].' sign-in?',
        $confirmation['name'] === 'allow_user_uploads' => 'Pause all uploads?',
        default => 'Require sign-in for everyone?',
    };
@endphp

<x-admin.ui.confirm-dialog
    id="rg-admin-settings-confirm"
    tone="warning"
    :icon="$confirmation['kind'] === 'provider' ? 'log-in' : ($confirmation['name'] === 'allow_user_uploads' ? 'upload' : 'lock')"
    :title="$title"
    wire:key="confirm-{{ $confirmation['kind'] }}-{{ $confirmation['name'] }}"
    x-on:dismiss="$wire.closeConfirmation()"
>
    @if ($confirmation['kind'] === 'provider')
        <p>This hides the {{ $confirmation['label'] }} buttons for this project and refuses its sign-ins. It doesn’t delete or unlink any account.</p>
    @elseif ($confirmation['name'] === 'allow_user_uploads')
        <p>Nobody can create new posts until uploads are turned back on. Existing posts, votes and comments are unaffected.</p>
    @else
        <p>Visitors without an account will see the sign-in page instead of the feed. Search engines stop indexing posts.</p>
    @endif

    @if ($confirmation['kind'] === 'provider')
        <x-slot:details>
            <ul class="rg-admin-dialog-facts">
                <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="users" :size="16" />{{ number_format($confirmation['accounts']) }} {{ \Illuminate\Support\Str::plural('account', $confirmation['accounts']) }} {{ $confirmation['accounts'] === 1 ? 'signs' : 'sign' }} in with {{ $confirmation['label'] }}. They stay linked.</li>
                <li class="rg-admin-dialog-facts__item"><x-admin.ui.icon name="key-round" :size="16" />People who only ever signed in with {{ $confirmation['label'] }} are shown on the login page how to set a password.</li>
            </ul>
        </x-slot:details>
    @endif

    <x-slot:footnote>Applies after you save the settings.</x-slot:footnote>

    <x-slot:actions>
        <x-admin.ui.button x-on:click="dismiss()" autofocus>Cancel</x-admin.ui.button>
        <x-admin.ui.button variant="primary" wire:click="confirmSwitchOff">{{ $confirmation['kind'] === 'provider' ? 'Turn off '.$confirmation['label'] : 'Turn off' }}</x-admin.ui.button>
    </x-slot:actions>
</x-admin.ui.confirm-dialog>
