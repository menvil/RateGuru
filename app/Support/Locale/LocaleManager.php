<?php

namespace App\Support\Locale;

use App\Support\Settings\ProjectSettingsManager;
use Symfony\Component\HttpFoundation\AcceptHeader;

/**
 * Which languages exist, which ones this project offers, and which one a
 * visitor gets.
 *
 * Three sets, kept apart on purpose:
 *
 *  - supported: installed with the application — declared in
 *    config/locales.php with a complete catalog. Admin translation editors and
 *    catalog checks work on these, so a language can be prepared before it is
 *    offered.
 *  - enabled: the installed languages this project offers its visitors —
 *    project_settings.enabled_locales once the project has chosen them, and
 *    until then the installed languages declared `enabled_by_default` in
 *    config/locales.php. Everything a visitor can pick or be served comes
 *    from here.
 *  - fallback: the technical emergency locale from config. Installed, but not
 *    necessarily offered, and never the answer to "which language does a new
 *    visitor get" — that is projectDefault().
 */
class LocaleManager
{
    public function __construct(private readonly ProjectSettingsManager $settings) {}

    /**
     * Every installed language, in config order.
     *
     * @return array<string, array{label: string, native: string, flag: string, enabled_by_default: bool}>
     */
    public function supported(): array
    {
        return config('locales.supported', []);
    }

    public function isSupported(string $locale): bool
    {
        return array_key_exists($locale, $this->supported());
    }

    /** The technical fallback: always an installed language. */
    public function fallback(): string
    {
        $fallback = config('locales.fallback', 'en');

        return $this->isSupported($fallback) ? $fallback : (array_key_first($this->supported()) ?? 'en');
    }

    /**
     * The installed languages a project offers before it has chosen its own,
     * in config order. Bootstrap policy only: a project that has chosen
     * (enabled_locales is set) never consults it again.
     *
     * @return list<string>
     */
    public function enabledByDefault(): array
    {
        return array_keys(array_filter(
            $this->supported(),
            fn (array $info): bool => $info['enabled_by_default'] === true,
        ));
    }

    /**
     * The installed languages this project offers, in config order.
     *
     * Read defensively: a stored code that is not installed is ignored, and a
     * stored value that leaves nothing at all resolves to the technical
     * fallback rather than to a site without a language. Writes never allow
     * that state (UpdateProjectLocaleSettingsAction); this only keeps a bad
     * row from taking the site down.
     *
     * @return array<string, array{label: string, native: string, flag: string, enabled_by_default: bool}>
     */
    public function enabled(): array
    {
        $stored = $this->settings->current()->enabledLocales();

        // Never chosen: whatever the installed languages offer by default — so
        // a language a release adds as not enabled by default stays withheld.
        $codes = $stored === null ? $this->enabledByDefault() : array_filter($stored, is_string(...));
        $enabled = array_intersect_key($this->supported(), array_flip($codes));

        if ($enabled !== []) {
            return $enabled;
        }

        $fallback = $this->fallback();

        return array_intersect_key($this->supported(), [$fallback => true]);
    }

    /** @return list<string> */
    public function enabledCodes(): array
    {
        return array_keys($this->enabled());
    }

    public function isEnabled(string $locale): bool
    {
        return array_key_exists($locale, $this->enabled());
    }

    /**
     * The language a visitor gets when nothing about them points anywhere
     * else: the project default when it is offered, then the technical
     * fallback when that is offered, then the first offered language.
     */
    public function projectDefault(): string
    {
        $enabled = $this->enabledCodes();
        $default = $this->settings->current()->defaultLocale();

        if (in_array($default, $enabled, true)) {
            return $default;
        }

        $fallback = $this->fallback();

        if (in_array($fallback, $enabled, true)) {
            return $fallback;
        }

        return $enabled[0] ?? $fallback;
    }

    /**
     * A locale to serve for a value read from somewhere: the value itself when
     * it is offered, otherwise the project default. For reading only — a write
     * of a locale nobody offers is an error, not something to correct quietly.
     */
    public function enabledOrDefault(?string $locale): string
    {
        return $locale !== null && $this->isEnabled($locale) ? $locale : $this->projectDefault();
    }

    /**
     * The offered language a browser asks for, or null when it asks for none of
     * them.
     *
     * Languages are tried in the header's quality order, and each one first as
     * written and then by its primary language, so `ru-RU` reaches `ru`. A
     * language refused with `q=0` and the `*` wildcard never match. Null is a
     * real answer: the caller falls through to the project default, never to
     * whichever offered language happens to come first.
     */
    public function fromAcceptLanguage(?string $header): ?string
    {
        foreach (AcceptHeader::fromString($header)->all() as $item) {
            if ($item->getQuality() <= 0) {
                continue;
            }

            $tag = strtolower(str_replace('_', '-', trim($item->getValue())));

            foreach (array_unique([$tag, explode('-', $tag)[0]]) as $candidate) {
                if ($candidate !== '*' && $this->isEnabled($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    public function label(string $locale): string
    {
        return $this->supported()[$locale]['label'] ?? $locale;
    }

    public function nativeLabel(string $locale): string
    {
        return $this->supported()[$locale]['native'] ?? $locale;
    }

    public function flag(string $locale): string
    {
        return $this->supported()[$locale]['flag'] ?? '';
    }
}
