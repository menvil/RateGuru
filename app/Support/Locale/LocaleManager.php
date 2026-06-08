<?php

namespace App\Support\Locale;

class LocaleManager
{
    public function supported(): array
    {
        return config('locales.supported', []);
    }

    public function isSupported(string $locale): bool
    {
        return array_key_exists($locale, $this->supported());
    }

    public function fallback(): string
    {
        $fallback = config('locales.fallback', 'en');

        return $this->isSupported($fallback) ? $fallback : (array_key_first($this->supported()) ?? 'en');
    }

    public function normalize(?string $locale): string
    {
        if ($locale && $this->isSupported($locale)) {
            return $locale;
        }

        return $this->fallback();
    }

    public function label(string $locale): string
    {
        return $this->supported()[$locale]['label'] ?? $locale;
    }

    public function nativeLabel(string $locale): string
    {
        return $this->supported()[$locale]['native'] ?? $locale;
    }
}
