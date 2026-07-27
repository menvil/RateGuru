<?php

namespace App\Support\Settings;

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

    /** @return array{title: string, content: string} */
    public function staticPage(string $pageKey): array
    {
        $page = $this->data['static_pages'][$pageKey] ?? null;

        if (! is_array($page)) {
            throw new \InvalidArgumentException("Unknown static page [{$pageKey}].");
        }

        $locale = app()->getLocale();
        $fallbackLocale = config('locales.fallback', 'en');
        $localized = is_array($page[$locale] ?? null) ? $page[$locale] : [];
        $fallback = is_array($page[$fallbackLocale] ?? null) ? $page[$fallbackLocale] : [];

        return [
            'title' => $this->localizedStaticPageValue($localized, $fallback, 'title'),
            'content' => $this->localizedStaticPageValue($localized, $fallback, 'content'),
        ];
    }

    public function defaultLocale(): string
    {
        return $this->data['default_locale'];
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
     * @param  array<string, mixed>  $localized
     * @param  array<string, mixed>  $fallback
     */
    private function localizedStaticPageValue(array $localized, array $fallback, string $key): string
    {
        $value = $localized[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        $fallbackValue = $fallback[$key] ?? '';

        return is_string($fallbackValue) ? $fallbackValue : '';
    }
}
