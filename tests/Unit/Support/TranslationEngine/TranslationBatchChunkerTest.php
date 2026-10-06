<?php

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationChunkPlan;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\TranslationBatchChunker;
use App\Support\TranslationEngine\TranslationPromptBuilder;

/**
 * A logical batch cut into provider requests: whichever of the two limits a
 * request reaches first closes it, items keep their order, and an item too
 * large for any request is set aside whole — never cut.
 */
function translationChunkPlan(TranslationBatchRequest $batch, ?TranslationProviderLimits $limits = null): TranslationChunkPlan
{
    return (new TranslationBatchChunker(new TranslationPromptBuilder))
        ->chunk($batch, $limits ?? new TranslationProviderLimits(50, 60_000));
}

/** @return list<int> */
function translationChunkSizes(TranslationChunkPlan $plan): array
{
    return array_map(fn (TranslationBatchRequest $chunk): int => count($chunk->items), $plan->chunks);
}

it('cuts by item count when the texts are short', function (int $items, array $sizes) {
    $plan = translationChunkPlan(translationBatch(translationItems($items)));

    expect(translationChunkSizes($plan))->toBe($sizes)
        ->and($plan->oversized)->toBe([]);
})->with([
    '1' => [1, [1]],
    '39' => [39, [39]],
    '50' => [50, [50]],
    '51' => [51, [50, 1]],
    '104' => [104, [50, 50, 4]],
]);

it('cuts by provider-visible characters when the texts are long, well under the item limit', function () {
    // Ten texts of 10,000 characters each: under 50 items, over 60,000
    // characters together.
    $prompts = new TranslationPromptBuilder;
    $batch = translationBatch(translationItems(10, ['sourceText' => str_repeat('a', 10_000), 'maxLength' => null]));

    $plan = translationChunkPlan($batch);

    expect(count($plan->chunks))->toBeGreaterThan(1)
        ->and(translationChunkSizes($plan))->toBe([5, 5]);

    foreach ($plan->chunks as $chunk) {
        expect($prompts->providerVisibleLength($chunk))->toBeLessThanOrEqual(60_000);
    }
});

it('applies both limits at once, closing each request at whichever it reaches first', function () {
    $prompts = new TranslationPromptBuilder;

    $short = translationItems(5);
    $long = array_map(
        fn (int $number) => translationItem(['id' => "long:{$number}", 'sourceText' => str_repeat('l', 2_000), 'maxLength' => null]),
        [1, 2, 3],
    );
    $batch = translationBatch([...$short, ...$long]);

    // Room for exactly one short and two long items after the envelope.
    $limit = $prompts->envelopeLength($batch)
        + $prompts->itemLength($short[4]) + 1
        + $prompts->itemLength($long[0]) + 1
        + $prompts->itemLength($long[1]);

    $plan = translationChunkPlan($batch, new TranslationProviderLimits(4, $limit));

    // Four short items close on the count; one short and two long close on the
    // characters, since the third long one does not fit.
    expect(translationChunkSizes($plan))->toBe([4, 3, 1])
        ->and($plan->chunks[0]->itemIds())->toBe(['item:1', 'item:2', 'item:3', 'item:4'])
        ->and($plan->chunks[1]->itemIds())->toBe(['item:5', 'long:1', 'long:2'])
        ->and($plan->chunks[2]->itemIds())->toBe(['long:3'])
        ->and($prompts->providerVisibleLength($plan->chunks[1]))->toBe($limit);
});

it('keeps every request within both limits and every item in its original order', function () {
    $prompts = new TranslationPromptBuilder;
    $items = [];

    foreach (range(1, 120) as $number) {
        // Lengths that vary deterministically from a few characters to a few thousand.
        $items[] = translationItem([
            'id' => "item:{$number}",
            'sourceText' => str_repeat('Wort ', ($number * 37) % 900 + 1),
            'maxLength' => null,
        ]);
    }

    $batch = translationBatch($items, glossary: ['RateGuru' => 'RateGuru']);
    $limits = new TranslationProviderLimits(50, 20_000);
    $plan = translationChunkPlan($batch, $limits);

    foreach ($plan->chunks as $chunk) {
        expect(count($chunk->items))->toBeLessThanOrEqual(50)
            ->and($prompts->providerVisibleLength($chunk))->toBeLessThanOrEqual(20_000);
    }

    $sent = array_merge(...array_map(fn (TranslationBatchRequest $chunk): array => $chunk->itemIds(), $plan->chunks));

    expect($sent)->toBe($batch->itemIds())
        ->and($plan->oversized)->toBe([]);
});

it('sets an item too large for any request aside whole, and still sends every other item', function () {
    $huge = translationItem([
        'id' => 'post:713:body',
        'sourceText' => str_repeat('Ein sehr langer Absatz. ', 3_000),
        'contentType' => 'post.body',
        'multiline' => true,
        'maxLength' => null,
    ]);
    $batch = translationBatch([...translationItems(2), $huge, ...array_slice(translationItems(4), 2)]);

    $plan = translationChunkPlan($batch);

    expect($plan->oversized)->toHaveCount(1)
        ->and($plan->oversized[0])->toBe($huge)
        ->and($plan->oversized[0]->sourceText)->toBe(str_repeat('Ein sehr langer Absatz. ', 3_000))
        ->and(translationChunkSizes($plan))->toBe([4])
        ->and($plan->chunks[0]->itemIds())->toBe(['item:1', 'item:2', 'item:3', 'item:4']);
});

it('sends an item that exactly fills a request, and sets aside one a single character over', function () {
    $prompts = new TranslationPromptBuilder;
    $item = translationItem(['sourceText' => str_repeat('x', 1_000), 'maxLength' => null]);
    $batch = translationBatch([$item]);

    $exact = $prompts->envelopeLength($batch) + $prompts->itemLength($item);

    expect(translationChunkSizes(translationChunkPlan($batch, new TranslationProviderLimits(50, $exact))))->toBe([1])
        ->and(translationChunkPlan($batch, new TranslationProviderLimits(50, $exact - 1))->oversized)->toBe([$item]);
});

it('counts the glossary every request repeats, so a large glossary leaves room for fewer items', function () {
    $items = translationItems(30, ['sourceText' => str_repeat('b', 500), 'maxLength' => null]);
    $glossary = [];

    foreach (range(1, 100) as $number) {
        $glossary["Fachbegriff{$number}"] = "Fachbegriff{$number}";
    }

    $limits = new TranslationProviderLimits(50, 10_000);

    $without = translationChunkPlan(translationBatch($items), $limits);
    $with = translationChunkPlan(translationBatch($items, glossary: $glossary), $limits);

    expect(count($with->chunks[0]->items))->toBeLessThan(count($without->chunks[0]->items))
        ->and(count($with->chunks))->toBeGreaterThan(count($without->chunks));

    foreach ($with->chunks as $chunk) {
        expect($chunk->glossary)->toBe($glossary)
            ->and((new TranslationPromptBuilder)->providerVisibleLength($chunk))->toBeLessThanOrEqual(10_000);
    }
});

it('counts what the provider is sent, not the source text alone', function () {
    // Context and existing translations are sent too, so they take room.
    $plain = translationItems(20, ['sourceText' => str_repeat('c', 300), 'context' => null, 'maxLength' => null]);
    $rich = translationItems(20, [
        'sourceText' => str_repeat('c', 300),
        'context' => str_repeat('A long description of where the text appears. ', 20),
        'existingTranslations' => ['fr' => str_repeat('f', 300), 'bg' => str_repeat('б', 300)],
        'maxLength' => null,
    ]);
    $limits = new TranslationProviderLimits(50, 10_000);

    expect(count(translationChunkPlan(translationBatch($rich), $limits)->chunks))
        ->toBeGreaterThan(count(translationChunkPlan(translationBatch($plain), $limits)->chunks));
});
