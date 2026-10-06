<?php

namespace App\Support\TranslationEngine\Data;

/**
 * How one batch is sent: the provider requests it is cut into, and the items
 * too large to fit any request on their own.
 */
final readonly class TranslationChunkPlan
{
    /**
     * @param  list<TranslationBatchRequest>  $chunks  each within the provider's limits, together in the batch's order
     * @param  list<TranslationItem>  $oversized  items that exceed the limits alone; they are never cut, and are not sent
     */
    public function __construct(
        public array $chunks,
        public array $oversized,
    ) {}
}
