{{--
    The sign-in providers, one settings row each (FRM-06): whether its keys
    are set on the server, how many accounts sign in with it, and Enabled or
    Off. A provider whose keys are missing can be on: its buttons stay hidden
    until they are set. Switching one off asks first.
--}}
<x-admin.ui.card :title="__('admin.project_settings.sign_in_title')" :description="__('admin.project_settings.sign_in_description')" flush>
    @foreach ($providers as $provider)
        @php
            $hint = __('admin.project_settings.sign_in_accounts', ['count' => $provider['accounts']])
                .($provider['configured'] ? '' : ' '.__('admin.project_settings.sign_in_not_configured', ['keys' => $provider['keys']]));
        @endphp
        <x-admin.ui.switch
            layout="row"
            :label="__('admin.fields.sign_in_provider', ['provider' => $provider['label']])"
            :id="'rg-admin-settings-provider-'.$provider['value']"
            :checked="$data['sign_in_providers'][$provider['value']]"
            on="Enabled"
            off="Off"
            :on-hint="$hint"
            :off-hint="$hint"
            wire:model.live="data.sign_in_providers.{{ $provider['value'] }}"
        >
            <x-slot:note>
                <x-admin.ui.badge :tone="$provider['configured'] ? 'success' : 'danger'">{{ $provider['configured'] ? 'Configured' : 'Not configured' }}</x-admin.ui.badge>
            </x-slot:note>
        </x-admin.ui.switch>
    @endforeach
</x-admin.ui.card>
