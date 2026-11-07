@php
    /*
     * The drawer's Other languages: what each language the project offers
     * besides English holds for this record, read-only — the English
     * fallback a visitor gets where a translation is missing, or “Not
     * translated yet” for a record not created yet — and Translate, into
     * Translation Center on this record. Translations are edited there and
     * nowhere else; changing the English name here changes none of them.
     *
     * $translate is null when the record cannot be translated yet, with
     * $translateReason saying why.
     */
@endphp

<section class="rg-admin-taxonomy__languages" aria-labelledby="{{ $id }}-languages">
    <div class="rg-admin-section-label">
        <h3 id="{{ $id }}-languages" class="rg-admin-section-label__text">Other languages</h3>
        <span class="rg-admin-section-label__rule" aria-hidden="true"></span>
    </div>

    @if ($chips === [])
        <p class="rg-admin-hint">English is the only language visitors are offered. Languages are enabled on the Languages page.</p>
    @else
        <ul class="rg-admin-taxonomy__translations">
            @foreach ($chips as $chip)
                <li class="rg-admin-taxonomy__translation">
                    <span class="rg-admin-taxonomy__translation-flag" aria-hidden="true">{{ $chip['flag'] }}</span>
                    <span class="rg-admin-taxonomy__translation-language" lang="{{ $chip['code'] }}">{{ $chip['native'] }}</span>
                    @if ($chip['value'] !== null)
                        <span class="rg-admin-taxonomy__translation-value" lang="{{ $chip['code'] }}" title="{{ $chip['value'] }}">{{ $chip['value'] }}</span>
                    @else
                        <span class="rg-admin-taxonomy__translation-value rg-admin-taxonomy__translation-value--fallback">
                            {{ $reference === '' ? 'Not translated yet' : 'English fallback: '.$reference }}
                        </span>
                        <x-admin.ui.badge tone="warning">Missing</x-admin.ui.badge>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="rg-admin-taxonomy__translate">
            @if ($translate !== null)
                <x-admin.ui.button size="sm" icon="languages" :href="$translate">Translate</x-admin.ui.button>
            @else
                <span class="rg-admin-hint" id="{{ $id }}-translate-reason">{{ $translateReason }}</span>
                <x-admin.ui.button size="sm" icon="languages" disabled aria-describedby="{{ $id }}-translate-reason">Translate</x-admin.ui.button>
            @endif
        </div>
    @endif
</section>
