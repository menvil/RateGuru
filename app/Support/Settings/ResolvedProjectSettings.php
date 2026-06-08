<?php

namespace App\Support\Settings;

class ResolvedProjectSettings
{
    public function __construct(private readonly array $data) {}

    public function siteName(): string
    {
        return $this->data['site_name'];
    }

    public function siteTagline(): ?string
    {
        return $this->data['site_tagline'];
    }

    public function siteDescription(): ?string
    {
        return $this->data['site_description'];
    }

    public function objectSingularName(): string
    {
        return $this->data['object_singular_name'];
    }

    public function objectPluralName(): string
    {
        return $this->data['object_plural_name'];
    }

    public function uploadCtaLabel(): string
    {
        return $this->data['upload_cta_label'];
    }

    public function feedTitle(): string
    {
        return $this->data['feed_title'];
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
