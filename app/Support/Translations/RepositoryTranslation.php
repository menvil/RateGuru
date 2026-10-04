<?php

namespace App\Support\Translations;

/**
 * One translatable value the repository ships — a preset's category name, a
 * static page's title — with the stable identity that finds the same thing in
 * a project's database.
 */
final readonly class RepositoryTranslation
{
    /**
     * @param  array<string, string>  $identity  how the database row is found: slug, key, group + option, field, page
     * @param  array<string, mixed>  $values  the value per language, as written
     */
    public function __construct(
        public ProjectContentSection $section,
        public string $source,
        public array $identity,
        public string $field,
        public array $values,
    ) {}

    /** The reference-language value, or null when there is nothing to translate. */
    public function reference(): ?string
    {
        return $this->value(TranslatableField::REFERENCE_LOCALE);
    }

    /** The value for a language, or null when the repository has none. */
    public function value(string $locale): ?string
    {
        $value = $this->values[$locale] ?? null;

        return TranslatableField::isPresent($value) ? $value : null;
    }
}
