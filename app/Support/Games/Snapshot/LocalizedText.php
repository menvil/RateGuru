<?php

namespace App\Support\Games\Snapshot;

/**
 * A text as a version froze it: its English and every translation it had,
 * by locale. Read in a locale, it falls back to English where that locale has
 * none — the semantics of TranslatableField::resolve().
 */
final readonly class LocalizedText
{
    public const REFERENCE = 'en';

    /** @param  array<string, string>  $values */
    private function __construct(public array $values) {}

    /** @param  mixed  $values  a locale-to-text map; anything else is empty */
    public static function of(mixed $values): self
    {
        $clean = [];

        if (is_array($values)) {
            foreach ($values as $locale => $text) {
                if (is_string($locale) && is_string($text) && trim($text) !== '') {
                    $clean[$locale] = $text;
                }
            }
        }

        return new self($clean);
    }

    /**
     * The English base and its translations as one map, blank ones left out.
     *
     * @param  array<string, mixed>|null  $translations
     */
    public static function fromColumns(?string $english, ?array $translations): self
    {
        return self::of([self::REFERENCE => (string) $english, ...array_diff_key($translations ?? [], [self::REFERENCE => true])]);
    }

    public function in(string $locale): string
    {
        return $this->values[$locale] ?? $this->values[self::REFERENCE] ?? '';
    }

    public function english(): string
    {
        return $this->values[self::REFERENCE] ?? '';
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->values;
    }
}
