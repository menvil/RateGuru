<?php

namespace App\Support\Translations;

/**
 * One piece of project content a language has no translation for.
 */
final readonly class MissingProjectTranslation
{
    /**
     * @param  int|null  $recordId  the model's id; null for project settings and static pages
     * @param  int|null  $parentId  the rating group of a rating option
     * @param  string  $key  the stable identity: slug, key, group.option, settings field or page key
     * @param  string  $label  what an administrator recognises it by
     * @param  string|null  $reference  the reference (English) text a translation is made from
     */
    public function __construct(
        public ProjectContentSection $section,
        public ?int $recordId,
        public ?int $parentId,
        public string $key,
        public string $label,
        public string $field,
        public ?string $reference = null,
    ) {}

    /** The same item, carrying the reference text it is translated from. */
    public function withReference(string $reference): self
    {
        return new self($this->section, $this->recordId, $this->parentId, $this->key, $this->label, $this->field, $reference);
    }
}
