@php
    /*
     * The static pages: the list on the left — each page's title, whether its
     * translations are complete, “edited” while its draft is unsaved — and
     * the open page on the right, its English title and content (FRM-02/03)
     * with which offered languages miss what, and View page.
     */
    use App\Filament\Pages\ProjectSettingsPage;
    use App\Support\Translations\ProjectTranslationCatalog;

    $current = $pages[$page];
    [$titleLimit] = ProjectTranslationCatalog::FIELDS['static_pages']['title'];
    [$contentLimit] = ProjectTranslationCatalog::FIELDS['static_pages']['content'];
@endphp

<div class="rg-admin-settings__pages">
    <x-admin.ui.card flush>
        <nav class="rg-admin-settings__page-list" aria-label="Static pages">
            @foreach ($pages as $key => $item)
                @php($refused = $errors->has('data.static_pages.'.$key.'.en.title') || $errors->has('data.static_pages.'.$key.'.en.content'))
                <a
                    href="{{ ProjectSettingsPage::getUrl(['tab' => 'pages', 'page' => $key]) }}"
                    @class(['rg-admin-settings__page', 'rg-admin-settings__page--current' => $key === $page])
                    @if ($key === $page) aria-current="page" @endif
                    wire:click.prevent="$set('page', '{{ $key }}')"
                >
                    <span class="rg-admin-settings__page-title">{{ $item['label'] }}</span>
                    <span @class(['rg-admin-settings__page-meta', 'rg-admin-settings__page-meta--due' => $item['missing'] > 0 || $refused])>
                        {{ $refused ? 'Can’t be saved yet' : ($item['missing'] > 0 ? $item['missing'].' '.\Illuminate\Support\Str::plural('translation', $item['missing']).' missing' : 'Translated') }}<span x-cloak x-show="JSON.stringify($wire.data.static_pages['{{ $key }}']) !== JSON.stringify($wire.saved.static_pages['{{ $key }}'])"> · edited</span>
                    </span>
                </a>
            @endforeach
        </nav>
    </x-admin.ui.card>

    <x-admin.ui.card flush wire:key="static-page-{{ $page }}">
        <div class="rg-admin-rating-group__options-header">
            <div class="rg-admin-rating-group__options-title">
                <h2 class="rg-admin-card__title">{{ $current['label'] }}</h2>
                <p class="rg-admin-card__description">{{ parse_url($current['url'], PHP_URL_PATH) }}</p>
            </div>
            <x-admin.ui.button size="sm" icon="arrow-up-right" :href="$current['url']" target="_blank" rel="noopener">View page</x-admin.ui.button>
        </div>

        <div class="rg-admin-card__body rg-admin-settings__fields">
            <x-admin.ui.text-field
                label="Title"
                tag="English · reference"
                id="rg-admin-settings-page-title"
                :value="$data['static_pages'][$page]['en']['title']"
                :error="$errors->first('data.static_pages.'.$page.'.en.title') ?: null"
                maxlength="{{ $titleLimit }}"
                wire:model="data.static_pages.{{ $page }}.en.title"
            />
            <x-admin.ui.textarea
                label="Content"
                id="rg-admin-settings-page-content"
                :value="$data['static_pages'][$page]['en']['content']"
                :rows="16"
                :limit="$contentLimit"
                :required="true"
                hint="Plain text. Line breaks are kept."
                :error="$errors->first('data.static_pages.'.$page.'.en.content') ?: null"
                wire:model="data.static_pages.{{ $page }}.en.content"
            />
        </div>

        <x-slot:footer class="rg-admin-settings__translations">
            <x-admin.ui.icon name="languages" :size="16" />
            <span class="rg-admin-settings__translations-text">{{ $current['translations'] }}</span>
            @if ($current['translate'] !== null)
                <a href="{{ $current['translate'] }}" class="rg-admin-link rg-admin-link--arrow">Translate <x-admin.ui.icon name="arrow-right" :size="14" /></a>
            @endif
        </x-slot:footer>
    </x-admin.ui.card>
</div>
