<?php

namespace App\Support\TranslationEngine\Data;

use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\TranslationLocale;

/**
 * Everything the engine is asked to translate in one call: one or more items,
 * into one target language, under one data classification and one glossary.
 *
 * There is no single-item request. One text is a batch of one and takes
 * exactly the path a batch of a hundred does, so there is one contract to get
 * right rather than two that drift.
 *
 * The target language is the batch's, not the item's, on purpose: a provider
 * request carries one target language, so German and French are two batches.
 *
 * How many items and how many characters a batch may carry is configuration
 * (translation.max_batch_items, translation.max_batch_chars), held by
 * TranslationService rather than here. What is checked here is that the batch
 * is well formed: at least one item, every id once, and no item already in
 * the target language — translating one would spend money to produce nothing.
 */
final readonly class TranslationBatchRequest
{
    public const MAX_GLOSSARY_TERMS = 200;

    public const MAX_GLOSSARY_ENTRY_LENGTH = 100;

    /** @var list<TranslationItem> at least one, every id once */
    public array $items;

    /** @var array<string, string> source term => the form it must take in the translation */
    public array $glossary;

    /**
     * @param  string  $targetLocale  the language every item is translated into
     * @param  TranslationDataClassification  $dataClassification  decides which providers may receive the batch; never sent to one
     * @param  array<mixed>  $items  a list of at least one TranslationItem, every id once
     * @param  array<mixed>  $glossary  source term => the non-blank form it must take in the translation; a term mapped to itself stays untranslated
     */
    public function __construct(
        public string $targetLocale,
        public TranslationDataClassification $dataClassification,
        array $items,
        array $glossary = [],
    ) {
        $this->assertValidTargetLocale();

        $this->glossary = $this->validGlossary($glossary);
        $this->items = $this->validItems($items);
    }

    /**
     * The same batch — target, classification and glossary — over other items:
     * how a batch is cut into the requests a provider accepts.
     *
     * @param  list<TranslationItem>  $items
     */
    public function withItems(array $items): self
    {
        return new self($this->targetLocale, $this->dataClassification, $items, $this->glossary);
    }

    /** @return list<string> */
    public function itemIds(): array
    {
        return array_map(static fn (TranslationItem $item): string => $item->id, $this->items);
    }

    private function assertValidTargetLocale(): void
    {
        if (! TranslationLocale::isValid($this->targetLocale)) {
            throw InvalidTranslationRequestException::forBatch('has a target locale that is not a locale');
        }
    }

    /**
     * @param  array<mixed>  $glossary
     * @return array<string, string>
     */
    private function validGlossary(array $glossary): array
    {
        if (count($glossary) > self::MAX_GLOSSARY_TERMS) {
            throw InvalidTranslationRequestException::forBatch('has more than '.self::MAX_GLOSSARY_TERMS.' glossary terms');
        }

        $valid = [];

        foreach ($glossary as $term => $target) {
            // A numeric term such as "2024" arrives as an integer key: PHP
            // makes it one, and the payload restores it as a string.
            $term = (string) $term;

            if (! $this->isGlossaryEntry($term) || ! is_string($target) || ! $this->isGlossaryEntry($target)) {
                throw InvalidTranslationRequestException::forBatch(
                    'has a glossary entry that is not a non-blank string of at most '.self::MAX_GLOSSARY_ENTRY_LENGTH.' characters',
                );
            }

            $valid[$term] = $target;
        }

        return $valid;
    }

    private function isGlossaryEntry(string $entry): bool
    {
        return trim($entry) !== '' && mb_check_encoding($entry, 'UTF-8') && mb_strlen($entry) <= self::MAX_GLOSSARY_ENTRY_LENGTH;
    }

    /**
     * @param  array<mixed>  $items
     * @return list<TranslationItem>
     */
    private function validItems(array $items): array
    {
        if ($items === []) {
            throw InvalidTranslationRequestException::forBatch('has no items');
        }

        if (! array_is_list($items)) {
            throw InvalidTranslationRequestException::forBatch('items are not a list');
        }

        $valid = [];
        $seen = [];

        foreach ($items as $item) {
            if (! $item instanceof TranslationItem) {
                throw InvalidTranslationRequestException::forBatch('holds an item that is not a TranslationItem');
            }

            if (isset($seen[$item->id])) {
                throw InvalidTranslationRequestException::forItem($item->id, 'appears more than once in the batch');
            }

            if ($item->sourceLocale !== null && TranslationLocale::same($item->sourceLocale, $this->targetLocale)) {
                throw InvalidTranslationRequestException::forItem($item->id, "is already in the target locale {$this->targetLocale}");
            }

            $seen[$item->id] = true;
            $valid[] = $item;
        }

        return $valid;
    }
}
