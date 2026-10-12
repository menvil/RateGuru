<?php

namespace App\Filament\Support;

use App\Filament\Pages\TranslationCenterPage;
use App\Support\Locale\LocaleManager;
use App\Support\Translations\ProjectContentSection;
use App\Support\Translations\TranslatableField;

/**
 * Whether one record's English text is translated into each language the
 * project offers — the locale chips (STS-05) and the missing count a content
 * screen shows beside the English name, and the way into Translation Center
 * for it.
 *
 * Over the offered languages besides English, not every installed one: these
 * are what visitors meet, and a language prepared before it is offered is
 * counted on Languages and edited in Translation Center like any other. A
 * translation is one when TranslatableField says it is present; an English
 * entry is never read.
 *
 * @phpstan-type Chip array{code: string, label: string, native: string, flag: string, value: ?string}
 */
final class ContentTranslationStatus
{
    /** @var array<string, array{label: string, native: string, flag: string}>|null */
    private ?array $languages = null;

    public function __construct(private readonly LocaleManager $locales) {}

    /**
     * The offered languages besides English, in config order.
     *
     * @return array<string, array{label: string, native: string, flag: string}>
     */
    public function languages(): array
    {
        return $this->languages ??= array_map(
            fn (array $info): array => ['label' => $info['label'], 'native' => $info['native'], 'flag' => $info['flag']],
            array_diff_key($this->locales->enabled(), [TranslatableField::REFERENCE_LOCALE => true]),
        );
    }

    /**
     * One chip per offered language: what is stored for it, or null.
     *
     * @return list<Chip>
     */
    public function chips(mixed $translations): array
    {
        $chips = [];

        foreach ($this->languages() as $code => $language) {
            $value = is_array($translations) ? ($translations[$code] ?? null) : null;

            $chips[] = [
                'code' => $code,
                'label' => $language['label'],
                'native' => $language['native'],
                'flag' => $language['flag'],
                'value' => TranslatableField::isPresent($value) ? $value : null,
            ];
        }

        return $chips;
    }

    /** @param  list<Chip>  $chips */
    public static function missing(array $chips): int
    {
        return count(array_filter($chips, fn (array $chip): bool => $chip['value'] === null));
    }

    /** Whether the signed-in user may open Translation Center at all. */
    public function canTranslate(): bool
    {
        return TranslationCenterPage::canAccess();
    }

    /**
     * Translation Center on one record's text: in the first offered language
     * it is missing from, or the first offered one when none is missing, its
     * section chosen. Translation Center finds the unit for itself and shows
     * it whatever its other filters say.
     *
     * @param  list<Chip>  $chips
     */
    public function translateUrl(ProjectContentSection $section, int $recordId, string $field, array $chips): string
    {
        $missing = array_values(array_filter($chips, fn (array $chip): bool => $chip['value'] === null));
        $locale = ($missing[0] ?? $chips[0] ?? null)['code'] ?? null;

        return TranslationCenterPage::getUrl(array_filter([
            'locale' => $locale,
            'section' => $section->value,
            'unit' => "{$section->value}:{$recordId}:{$field}",
        ]));
    }

    /** Translation Center on a whole section, as the header band's link opens it. */
    public static function sectionUrl(ProjectContentSection $section): string
    {
        return TranslationCenterPage::getUrl(['section' => $section->value]);
    }
}
