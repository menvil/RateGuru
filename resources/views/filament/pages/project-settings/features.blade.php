{{--
    The feature flags, one settings row each (FRM-06): what it does, On or
    Off, “Differs from preset” while it does. Uploads and guest viewing ask
    first when switched off (ProjectSettingsPage::updatedData).
--}}
<x-admin.ui.card flush>
    @foreach (\App\Filament\Pages\ProjectSettingsPage::FLAGS as $flag => $help)
        <x-admin.ui.switch
            layout="row"
            :label="__('admin.fields.'.$flag)"
            :id="'rg-admin-settings-flag-'.$flag"
            :checked="$data['feature_flags'][$flag]"
            :on-hint="$help"
            :off-hint="$help"
            wire:model.live="data.feature_flags.{{ $flag }}"
        >
            <x-slot:note>
                <span class="rg-admin-settings__differs" x-cloak x-show="differs('feature_flags.{{ $flag }}')">Differs from preset</span>
            </x-slot:note>
        </x-admin.ui.switch>
    @endforeach
</x-admin.ui.card>
