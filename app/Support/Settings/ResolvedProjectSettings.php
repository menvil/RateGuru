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
        return TranslatableField::resolve(
            $this->data['site_tagline_translations'] ?? null,
            $this->data['site_tagline'] ?? ''
        ) ?: null;
    }

    public function siteDescription(): ?string
    {
        $base = $this->data['site_description'] ?? '';

        return TranslatableField::resolve(
            $this->data['site_description_translations'] ?? null,
            $base
        ) ?: null;
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
}
