<?php

namespace App\Support\TranslationEngine\Data;

/**
 * The answer to one TranslationBatchRequest: one result for every item, in the
 * order the items were given, and one entry for every provider call made.
 *
 * Some items may have succeeded while others failed — a provider call in the
 * middle of a batch can fail without taking the others with it. The engine
 * stores none of it; what is kept, and where, is the consumer's decision.
 */
final readonly class TranslationBatchResult
{
    /**
     * @param  list<TranslationItemResult>  $items  one per requested item, in request order
     * @param  list<TranslationProviderCall>  $calls  one per provider call, in the order they were made
     */
    public function __construct(
        public array $items,
        public array $calls,
    ) {}

    public function item(string $id): ?TranslationItemResult
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<TranslationItemResult> */
    public function successful(): array
    {
        return array_values(array_filter($this->items, static fn (TranslationItemResult $item): bool => $item->isSuccessful()));
    }

    /** @return list<TranslationItemResult> */
    public function failed(): array
    {
        return array_values(array_filter($this->items, static fn (TranslationItemResult $item): bool => ! $item->isSuccessful()));
    }
}
