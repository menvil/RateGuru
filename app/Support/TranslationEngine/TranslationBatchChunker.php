<?php

namespace App\Support\TranslationEngine;

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationChunkPlan;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;

/**
 * Cuts one logical batch into the requests a provider accepts.
 *
 * A request closes at whichever limit it reaches first — the item count or
 * the provider-visible characters — so 39 short texts are one request, 104
 * are 50 + 50 + 4, and ten long ones split sooner. Items keep the batch's
 * order and are packed greedily: a request takes the next item for as long as
 * it fits.
 *
 * An item too large for a request on its own is set aside, never cut. Cutting
 * a text mid-way would sever sentences from their context and could break a
 * placeholder or a Markdown construct in two; the item fails instead, and
 * every other item is still sent.
 *
 * Characters are measured over the JSON the provider is actually sent
 * (TranslationPromptBuilder), so the count includes keys, constraints,
 * existing translations, the glossary every request repeats, and escaping.
 */
final class TranslationBatchChunker
{
    public function __construct(
        private readonly TranslationPromptBuilder $prompts,
    ) {}

    public function chunk(TranslationBatchRequest $request, TranslationProviderLimits $limits): TranslationChunkPlan
    {
        $envelope = $this->prompts->envelopeLength($request);

        $chunks = [];
        $oversized = [];
        $current = [];
        $currentLength = $envelope;

        foreach ($request->items as $item) {
            $itemLength = $this->prompts->itemLength($item);

            if ($envelope + $itemLength > $limits->maxProviderVisibleChars) {
                $oversized[] = $item;

                continue;
            }

            // Items in the JSON list are separated by one comma.
            $separator = $current === [] ? 0 : 1;

            if ($current !== [] && (
                count($current) >= $limits->maxItems
                || $currentLength + $separator + $itemLength > $limits->maxProviderVisibleChars
            )) {
                $chunks[] = $request->withItems($current);
                $current = [];
                $currentLength = $envelope;
                $separator = 0;
            }

            $current[] = $item;
            $currentLength += $separator + $itemLength;
        }

        if ($current !== []) {
            $chunks[] = $request->withItems($current);
        }

        return new TranslationChunkPlan($chunks, $oversized);
    }
}
