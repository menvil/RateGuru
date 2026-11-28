{{--
    The feature flags, one settings row each (FRM-06): what it does, On or
    Off, “Differs from preset” while it does. Uploads and guest viewing ask
    first when switched off (ProjectSettingsPage::updatedData). Below them,
    the sites a link may not be imported from (BlockedImportDomains).
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

<x-admin.ui.card
    title="Blocked sites for links"
    description="A post can’t start from a link to these sites: nothing is fetched from them, and the author is told the site isn’t supported and asked to upload the file. A domain blocks every address under it — example.com blocks www.example.com and cdn.example.com too."
>
    <x-admin.ui.textarea
        label="Domains"
        id="rg-admin-settings-blocked-import-domains"
        :value="$data['blocked_import_domains'] ?? ''"
        :rows="5"
        placeholder="example.com"
        hint="One a line. A pasted link is fine: only its domain is kept."
        :error="$errors->first('data.blocked_import_domains') ?: null"
        data-testid="settings-blocked-import-domains"
        wire:model="data.blocked_import_domains"
    />
</x-admin.ui.card>
