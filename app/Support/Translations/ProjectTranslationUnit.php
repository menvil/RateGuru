<?php

namespace App\Support\Translations;

/**
 * One translatable field of this project's own content — a category's name, a
 * rating group's description, a static page's title — as the database holds
 * it now: its English reference text, what each language stores for it, and
 * the constraints its editor enforces.
 *
 * Units come only from ProjectTranslationCatalog, the one list of what is
 * translated. Everything that counts, lists or edits project translations
 * works on these, so what Languages counts as missing is exactly what
 * Translation Center can edit.
 */
final readonly class ProjectTranslationUnit
{
    /**
     * A placeholder the text fills in at runtime: `{contact_email}`. A
     * translation has to keep every one its English text has.
     */
    public const PLACEHOLDER = '/\{[A-Za-z0-9_]+\}/';

    /**
     * @param  string  $id  the stable identity, built from the record id rather than anything an administrator can rename: `categories:17:name`
     * @param  int|null  $recordId  the model's id; null for project settings and static pages
     * @param  int|null  $parentId  the rating group of a rating option
     * @param  string  $key  the business key an administrator knows it by: slug, key, group.option, settings field or page key
     * @param  string  $label  the content it belongs to, as an administrator recognises it
     * @param  string  $reference  the English text a translation is made from; blank when there is nothing to translate
     * @param  array<string, mixed>  $translations  what each language stores, as stored — not necessarily text
     * @param  string  $usage  where a visitor meets the text, for whoever translates it
     */
    public function __construct(
        public string $id,
        public ProjectContentSection $section,
        public ?int $recordId,
        public ?int $parentId,
        public string $key,
        public string $label,
        public string $field,
        public string $reference,
        public array $translations,
        public int $maxLength,
        public bool $multiline,
        public string $usage,
    ) {}

    /** A blank English text needs no translation, and has none counted (TranslatableField::isPresent()). */
    public function requiresTranslation(): bool
    {
        return trim($this->reference) !== '';
    }

    /** The stored translation for a language when it is one (TranslatableField::isPresent()), otherwise null. */
    public function translation(string $locale): ?string
    {
        $value = $this->translations[$locale] ?? null;

        return TranslatableField::isPresent($value) ? $value : null;
    }

    /** English is translated by its own base column; every other language by a stored text. */
    public function isTranslatedInto(string $locale): bool
    {
        return $locale === TranslatableField::REFERENCE_LOCALE || $this->translation($locale) !== null;
    }

    /**
     * The placeholders of the English text, each once, in the order they
     * first appear.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        preg_match_all(self::PLACEHOLDER, $this->reference, $matches);

        return array_values(array_unique($matches[0]));
    }

    /** The field as a word, “Name” or “Description”; null where it would only repeat the label, as for a project setting. */
    public function fieldLabel(): ?string
    {
        $field = ucfirst(str_replace('_', ' ', $this->field));

        return strcasecmp($field, $this->label) === 0 ? null : $field;
    }

    /** The business key with its section and field, as an administrator would write it: `categories.small-pets.name`. */
    public function qualifiedKey(): string
    {
        return $this->key === $this->field
            ? "{$this->section->value}.{$this->key}"
            : "{$this->section->value}.{$this->key}.{$this->field}";
    }

    /** The same unit, with what one language stores replaced: blank text removes the language. */
    public function withTranslation(string $locale, ?string $text): self
    {
        $translations = $this->translations;

        if ($text !== null && trim($text) !== '') {
            $translations[$locale] = $text;
        } else {
            unset($translations[$locale]);
        }

        return new self(
            $this->id, $this->section, $this->recordId, $this->parentId, $this->key, $this->label, $this->field,
            $this->reference, $translations, $this->maxLength, $this->multiline, $this->usage,
        );
    }
}
