{{--
    The group's own text in each language the project offers besides English
    — its label, and its description when it has one — and Open in
    Translation Center, on what it is missing. Read-only: translations are
    Translation Center's.
--}}
<section class="rg-admin-card__body rg-admin-rating-group__translations" aria-labelledby="rg-admin-rating-group-translations">
    <div class="rg-admin-section-label">
        <h2 id="rg-admin-rating-group-translations" class="rg-admin-section-label__text">Translations of this group</h2>
        <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
    </div>

    @if ($translations['rows'] === [])
        <p class="rg-admin-hint">English is the only language visitors are offered. Languages are enabled on the Languages page.</p>
    @else
        <ul class="rg-admin-rating-group__languages">
            @foreach ($translations['rows'] as $language)
                <li class="rg-admin-rating-group__language">
                    <span class="rg-admin-taxonomy__translation-flag" aria-hidden="true">{{ $language['flag'] }}</span>
                    <span class="rg-admin-rating-group__language-name" lang="{{ $language['code'] }}">{{ $language['native'] }}</span>
                    <span @class(['rg-admin-rating-group__language-state', 'rg-admin-rating-group__language-state--missing' => ! $language['complete']])>{{ $language['state'] }}</span>
                </li>
            @endforeach
        </ul>

        <div class="rg-admin-rating-group__translate">
            @if ($translations['url'] !== null)
                <x-admin.ui.button size="sm" icon="languages" :href="$translations['url']">Open in Translation Center</x-admin.ui.button>
            @else
                <x-admin.ui.button size="sm" icon="languages" disabled aria-describedby="rg-admin-rating-group-translate-reason">Open in Translation Center</x-admin.ui.button>
                <span class="rg-admin-hint" id="rg-admin-rating-group-translate-reason">{{ $translations['reason'] }}</span>
            @endif
        </div>
    @endif
</section>
