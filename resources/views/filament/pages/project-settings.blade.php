@php
    /*
     * Project settings (ProjectSettingsPage): the header band with the
     * installation preset, the tabs, and the open tab. Everything is one
     * draft, saved together from the top bar; the browser counts the unsaved
     * changes from the draft against `saved` (filament.pages.editor.script)
     * and compares the draft with the preset as it is typed.
     */
    use App\Filament\Pages\ProjectSettingsPage;

    $tabs = collect(ProjectSettingsPage::TABS)->map(fn (string $label, string $id): array => [
        'id' => $id,
        'label' => $label,
        'href' => ProjectSettingsPage::getUrl($id === 'identity' ? [] : ['tab' => $id]),
        'attributes' => ['wire:click.prevent' => "\$set('tab', '{$id}')"],
    ])->values()->all();

    // Which tabs hold a field Save refused, so a refusal on a tab not on screen is not missed.
    $refused = collect($errors->keys())->map(fn (string $key): string => match (true) {
        str_starts_with($key, 'data.static_pages.') => 'pages',
        in_array(\Illuminate\Support\Str::after($key, 'data.'), array_keys(ProjectSettingsPage::TEXT_FIELDS['identity']), true) => 'identity',
        in_array(\Illuminate\Support\Str::after($key, 'data.'), array_keys(ProjectSettingsPage::TEXT_FIELDS['labels']), true) => 'labels',
        str_starts_with($key, 'data.feature_flags.') => 'features',
        str_starts_with($key, 'data.sign_in_providers.') => 'providers',
        default => 'defaults',
    })->unique()->values()->all();
@endphp

<div
    class="rg-admin rg-admin-screen"
    x-data="{
        preset: @js($preset['values']),
        presetLabels: @js($preset['labels']),
        valueAt(path) {
            return path.split('.').reduce((value, key) => value?.[key], $wire.data)
        },
        differs(path) {
            return path in this.preset && String(this.valueAt(path) ?? '') !== String(this.preset[path])
        },
        shown(path, value) {
            if (typeof value === 'boolean') return value ? 'On' : 'Off'
            if (path === 'default_theme' || path === 'default_sort') return String(value).charAt(0).toUpperCase() + String(value).slice(1)

            return `“${value ?? ''}”`
        },
        get differences() {
            return Object.keys(this.preset).filter((path) => this.differs(path))
        },
        revert(path) {
            $wire.set(`data.${path}`, this.preset[path], false)
        },
    }"
    x-effect="$store.adminEditor.changes = rgAdminEditorChanges($wire.data, $wire.saved)"
>
    @include('filament.pages.editor.script')

    <section class="rg-admin-page-header rg-admin-settings__header" aria-labelledby="rg-admin-settings-title">
        <div class="rg-admin-page-header__text">
            <h1 id="rg-admin-settings-title" class="rg-admin-page-header__title">Project settings</h1>
            <p class="rg-admin-page-header__description rg-admin-rating-group__meta">
                <span>{{ $saved['site_name'] }}{{ $host !== '' ? ' · '.$host : '' }}</span>
                <span class="rg-admin-rating-group__divider" aria-hidden="true"></span>
                <span>English is the reference language</span>
            </p>
        </div>

        @include('filament.pages.project-settings.preset')
    </section>

    <div class="rg-admin-screen__body rg-admin-settings__body">
        @if ($refused !== [])
            <x-admin.ui.inline-notice tone="warning">
                Some settings can’t be saved yet: {{ collect($refused)->map(fn (string $id): string => ProjectSettingsPage::TABS[$id])->join(', ', ' and ') }}. Each field says what to change.
            </x-admin.ui.inline-notice>
        @endif

        <x-admin.ui.tabs label="Settings" :active="$tab" :items="$tabs" />

        @switch ($tab)
            @case('identity')
            @case('labels')
                @include('filament.pages.project-settings.text', ['fields' => ProjectSettingsPage::TEXT_FIELDS[$tab], 'translation' => $translations[$tab]])
                @break
            @case('defaults')
                @include('filament.pages.project-settings.defaults')
                @break
            @case('features')
                @include('filament.pages.project-settings.features')
                @break
            @case('providers')
                @include('filament.pages.project-settings.providers')
                @break
            @default
                @include('filament.pages.project-settings.pages')
        @endswitch
    </div>

    @if ($confirmation !== null)
        @include('filament.pages.project-settings.confirmation')
    @endif
</div>
