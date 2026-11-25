{{--
    How the feed and the threads put the visitor's language first
    (docs/architecture/language-ranking.md): the switch for the whole of it,
    the threads and OpenAI's checks, then the numbers it runs on.
--}}
<x-admin.ui.card flush>
    @foreach (\App\Filament\Pages\ProjectSettingsPage::LANGUAGE_SWITCHES as $switch => [$label, $help])
        <x-admin.ui.switch
            layout="row"
            :label="$label"
            :id="'rg-admin-settings-language-'.$switch"
            :checked="$data['language_ranking'][$switch]"
            :on-hint="$help"
            :off-hint="$help"
            wire:model.live="data.language_ranking.{{ $switch }}"
        />
    @endforeach
</x-admin.ui.card>

<x-admin.ui.card flush>
    <div class="rg-admin-settings__numbers">
        @foreach (\App\Filament\Pages\ProjectSettingsPage::LANGUAGE_NUMBERS as $number => [$label, $help, $least, $most, $unit])
            <x-admin.ui.number-stepper
                :label="$label"
                :id="'rg-admin-settings-language-'.$number"
                :value="$data['language_ranking'][$number]"
                :min="$least"
                :max="$most"
                :unit="$unit"
                :range="number_format($least).'–'.number_format($most)"
                :hint="$help"
                :error="$errors->first('data.language_ranking.'.$number) ?: null"
                wire:model="data.language_ranking.{{ $number }}"
            />
        @endforeach
    </div>
</x-admin.ui.card>
