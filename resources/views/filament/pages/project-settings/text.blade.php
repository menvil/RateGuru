@php
    /*
     * Site identity or Object labels (LAY-02 card, FRM-02/03): each English
     * text with what it is used for, “Differs from preset” while it does,
     * and in the sunken footer which offered languages miss which of them,
     * with Translate into Translation Center. Beside it, where visitors see
     * them, as they are typed.
     */
    use App\Filament\Pages\ProjectSettingsPage;
    use App\Support\Translations\ProjectTranslationCatalog;

    [$title, $description] = $tab === 'identity'
        ? ['Site identity', 'The name and copy used across the site, emails and link previews.']
        : ['Object labels', 'What this project calls the things people post. The same engine can host pets, cars or recipes.'];
@endphp

<div class="rg-admin-rating-group__cards">
    <x-admin.ui.card :title="$title" :description="$description">
        <div class="rg-admin-settings__fields">
            @foreach ($fields as $field => $help)
                @php
                    [$limit, $multiline] = ProjectTranslationCatalog::FIELDS['project_settings'][$field];
                    $required = in_array($field, ProjectSettingsPage::REQUIRED, true);
                @endphp
                <div class="rg-admin-settings__field">
                    @if ($multiline)
                        <x-admin.ui.textarea
                            :label="ProjectSettingsPage::fieldLabel($field)"
                            :id="'rg-admin-settings-'.$field"
                            :value="$data[$field]"
                            :limit="$limit"
                            :required="$required ? true : false"
                            :hint="$help"
                            :error="$errors->first('data.'.$field) ?: null"
                            wire:model="data.{{ $field }}"
                        />
                    @else
                        <x-admin.ui.text-field
                            :label="ProjectSettingsPage::fieldLabel($field)"
                            :id="'rg-admin-settings-'.$field"
                            :value="$data[$field]"
                            :required="$required ? true : false"
                            :hint="$help"
                            :error="$errors->first('data.'.$field) ?: null"
                            maxlength="{{ $limit }}"
                            wire:model="data.{{ $field }}"
                        />
                    @endif
                    <p class="rg-admin-settings__differs" x-cloak x-show="differs('{{ $field }}')">
                        Differs from preset · <span x-text="shown('{{ $field }}', preset['{{ $field }}'])"></span>
                    </p>
                </div>
            @endforeach
        </div>

        <x-slot:footer class="rg-admin-settings__translations">
            <x-admin.ui.icon name="languages" :size="16" />
            <span class="rg-admin-settings__translations-text">{{ $translation['text'] }}</span>
            @if ($translation['url'] !== null)
                <a href="{{ $translation['url'] }}" class="rg-admin-link rg-admin-link--arrow">Translate <x-admin.ui.icon name="arrow-right" :size="14" /></a>
            @endif
        </x-slot:footer>
    </x-admin.ui.card>

    @include('filament.pages.project-settings.preview')
</div>
