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
     * A static page in the current language, field by field: the stored text
     * of that language, otherwise the stored English. Only what the project
     * stores is shown — config/static-pages.php seeded it once and is not read
     * here.
     *
     * The page itself must be one the application has (config keys name the
     * built-in pages); its content may be missing until it is filled in, which
     * shows as empty.
     *
     * @return array{title: string, content: string}
     */
    public function staticPage(string $pageKey): array
    {
        if (! array_key_exists($pageKey, (array) config('static-pages.defaults', []))) {
            throw new \InvalidArgumentException("Unknown static page [{$pageKey}].");
        }

        $page = $this->data['static_pages'][$pageKey] ?? [];
        $page = is_array($page) ? $page : [];
        $locale = app()->getLocale();

        return [
            'title' => $this->staticPageText($page, $locale, 'title'),
            'content' => $this->staticPageText($page, $locale, 'content'),
        ];
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

    /** @param  array<string, mixed>  $page  the page as the project stores it */
    private function staticPageText(array $page, string $locale, string $field): string
    {
        return $this->staticPageField($page, $locale, $field)
            ?? $this->staticPageField($page, TranslatableField::REFERENCE_LOCALE, $field)
            ?? '';
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
