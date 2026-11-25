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

@if ($languageStatus !== null)
    @php
        $posts = $languageStatus['posts'];
        $total = max(1, array_sum($posts));
        $checks = $languageStatus['checks'];
        $share = fn (array $pair): string => $pair['compared'] === 0 ? '—' : round(100 * $pair['agreed'] / $pair['compared']).'%';
    @endphp

    {{-- How the languages look now: what php artisan languages:report prints, for an installation nobody runs commands on. --}}
    <x-admin.ui.card>
        <div class="rg-admin-switch-row__head">
            <span class="rg-admin-switch-row__label">How languages look now</span>
        </div>
        @if ($posts === [])
            <p class="rg-admin-switch-row__hint">No published posts yet.</p>
        @else
            <p class="rg-admin-switch-row__hint">
                Published posts by language:
                {{ collect($posts)->take(12)->map(fn (int $count, string $language): string => $language.' '.number_format($count).' ('.round(100 * $count / $total).'%)')->implode(' · ') }}{{ count($posts) > 12 ? ' · …' : '' }}
            </p>
        @endif
        <p class="rg-admin-switch-row__hint">
            Texts read by OpenAI: {{ number_format($checks['done']) }} · waiting: {{ number_format($checks['pending'] + $checks['sending']) }} · given up, the server’s reading kept: {{ number_format($checks['failed']) }} · with no words to read: {{ number_format($checks['skipped']) }}.
            Posts and comments written before languages were kept are read a few hundred a minute on their own.
        </p>
        <p class="rg-admin-switch-row__hint">
            The server’s own reading agreed with OpenAI on {{ $share($languageStatus['readings']) }} of {{ number_format($languageStatus['readings']['compared']) }} texts both read;
            a post’s text was in its author’s language in {{ $share($languageStatus['authors']) }} of {{ number_format($languageStatus['authors']['compared']) }} posts.
        </p>
    </x-admin.ui.card>
@endif
