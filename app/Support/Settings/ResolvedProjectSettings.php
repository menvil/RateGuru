<?php

namespace App\Support\Settings;

use App\Enums\SocialProvider;
use App\Support\Translations\TranslatableField;

class ResolvedProjectSettings
{
    public function __construct(private readonly array $data) {}

    public function siteName(): string
    {
        return TranslatableField::resolve(
            $this->data['site_name_translations'] ?? null,
            $this->data['site_name']
        );
    }

    public function siteTagline(): ?string
    {
        $value = TranslatableField::resolve(
            $this->data['site_tagline_translations'] ?? null,
            $this->data['site_tagline'] ?? ''
        );

        return $value !== '' ? $value : null;
    }

    public function siteDescription(): ?string
    {
        $value = TranslatableField::resolve(
            $this->data['site_description_translations'] ?? null,
            $this->data['site_description'] ?? ''
        );

        return $value !== '' ? $value : null;
    }

    public function objectSingularName(): string
    {
        return TranslatableField::resolve(
            $this->data['object_singular_name_translations'] ?? null,
            $this->data['object_singular_name']
        );
    }

    public function objectPluralName(): string
    {
        return TranslatableField::resolve(
            $this->data['object_plural_name_translations'] ?? null,
            $this->data['object_plural_name']
        );
    }

    public function uploadCtaLabel(): string
    {
        return TranslatableField::resolve(
            $this->data['upload_cta_label_translations'] ?? null,
            $this->data['upload_cta_label']
        );
    }

    public function feedTitle(): string
    {
        return TranslatableField::resolve(
            $this->data['feed_title_translations'] ?? null,
            $this->data['feed_title']
        );
    }

    /**
     * A static page in the current language, field by field.
     *
     * The page's English is the stored English, or the configured English
     * where none is stored. Another language shows its own text — stored, or
     * configured where none is stored — until the project rewrites that
     * field's English. From then on the configured translation, and a stored
     * copy of it, translate text the page no longer shows, so the visitor gets
     * the current English instead; only a stored text of their own language
     * that differs from the configured one is shown. This is the rule the
     * Languages page counts missing translations by
     * (ProjectTranslationCompleteness): what it lists as missing is what a
     * visitor falls back from.
     *
     * @return array{title: string, content: string}
     */
    public function staticPage(string $pageKey): array
    {
        $page = $this->data['static_pages'][$pageKey] ?? null;

        if (! is_array($page)) {
            throw new \InvalidArgumentException("Unknown static page [{$pageKey}].");
        }

        $configured = config("static-pages.defaults.{$pageKey}", []);
        $configured = is_array($configured) ? $configured : [];
        $locale = app()->getLocale();

        return [
            'title' => $this->staticPageText($page, $configured, $locale, 'title'),
            'content' => $this->staticPageText($page, $configured, $locale, 'content'),
        ];
    }

    /**
     * The project default as stored. Not guaranteed to be offered: read
     * LocaleManager::projectDefault() for the one to use.
     */
    public function defaultLocale(): string
    {
        return $this->data['default_locale'];
    }

    /**
     * The codes stored as offered, unvalidated: null when the project never
     * chose (the languages enabled by default are offered), and an empty list
     * for a value that is not a list at all. LocaleManager::enabled() decides
     * what they mean.
     *
     * @return array<mixed>|null
     */
    public function enabledLocales(): ?array
    {
        $stored = $this->data['enabled_locales'] ?? null;

        if ($stored === null) {
            return null;
        }

        return is_array($stored) ? $stored : [];
    }

    public function defaultTheme(): string
    {
        return $this->data['default_theme'];
    }

    public function defaultSort(): string
    {
        return $this->data['default_sort'];
    }

    public function activePresetKey(): ?string
    {
        return $this->data['active_preset_key'];
    }

    public function featureFlag(string $key, bool $default = true): bool
    {
        return (bool) ($this->data['feature_flags'][$key] ?? $default);
    }

    /**
     * Whether the admin has left sign-in with this provider on. Every
     * provider is on until it is explicitly switched off. Whether it can
     * actually be used also depends on its keys — see
     * SocialProviderAvailability.
     */
    public function signInProviderTurnedOn(SocialProvider $provider): bool
    {
        $providers = $this->data['sign_in_providers'] ?? [];

        return (bool) (is_array($providers) ? ($providers[$provider->value] ?? true) : true);
    }

    /**
     * @param  array<string, mixed>  $page  the page as served: configured, with each stored language in its place
     * @param  array<string, mixed>  $configured  the page as config/static-pages.php ships it
     */
    private function staticPageText(array $page, array $configured, string $locale, string $field): string
    {
        $reference = TranslatableField::REFERENCE_LOCALE;
        $english = $this->staticPageField($page, $reference, $field);
        $configuredEnglish = $this->staticPageField($configured, $reference, $field);
        $current = $english ?? $configuredEnglish ?? '';

        $text = $this->staticPageField($page, $locale, $field);
        $configuredText = $this->staticPageField($configured, $locale, $field);

        if ($english === null || $english === $configuredEnglish) {
            return $text ?? $configuredText ?? $current;
        }

        // The English was rewritten: the configured translation, stored or
        // not, is of the old English and does not count.
        return $text !== null && $text !== $configuredText ? $text : $current;
    }

    /**
     * One field of one language of a page, or null where it has no text
     * (TranslatableField::isPresent()).
     *
     * @param  array<string, mixed>  $page
     */
    private function staticPageField(array $page, string $locale, string $field): ?string
    {
        $value = is_array($page[$locale] ?? null) ? ($page[$locale][$field] ?? null) : null;

        return TranslatableField::isPresent($value) ? $value : null;
    }
}
