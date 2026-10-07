<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\TranslationPromptBuilder;
use App\Support\Translations\Generation\ProjectTranslationGenerationPlan;
use App\Support\Translations\Generation\ProjectTranslationGenerationPlanner;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;

/**
 * Generate missing cut into chunks of one provider request each — by the
 * configured provider's own limits and the engine's own chunker.
 */
/**
 * Plans these categories for a provider with these limits — or with the ones
 * $limits works out from the request itself.
 *
 * @param  TranslationProviderLimits|(Closure(TranslationBatchRequest): TranslationProviderLimits)  $limits
 */
function plannedFor(array $records, TranslationProviderLimits|Closure $limits): ProjectTranslationGenerationPlan
{
    [$target] = twoTranslatedLocales();
    $units = array_values(app(ProjectTranslationCatalog::class)->findMany(array_map(fn (Category|RatingGroup $record): string => plannerUnit($record), $records)));
    $request = app(ProjectTranslationRequestFactory::class)->make($units, $target);

    useScriptedTranslationProvider(ScriptedTranslationProvider::translating($limits instanceof Closure ? $limits($request) : $limits));

    return app(ProjectTranslationGenerationPlanner::class)->plan($request);
}

/** A category's name, or a rating group's description — a long, multiline text. */
function plannerUnit(Category|RatingGroup $record): string
{
    return $record instanceof Category ? "categories:{$record->id}:name" : "rating_groups:{$record->id}:description";
}

function expectEveryChunkFits(ProjectTranslationGenerationPlan $plan): void
{
    foreach ($plan->chunks as $chunk) {
        expect(count($chunk->items))->toBeLessThanOrEqual($plan->maxItems)
            ->and(app(TranslationPromptBuilder::class)->providerVisibleLength($chunk))->toBeLessThanOrEqual($plan->maxProviderVisibleChars);
    }
}

beforeEach(fn () => ProjectSettings::factory()->create());

it('plans one chunk for a single short text, and for 39', function (int $count) {
    $plan = plannedFor(Category::factory()->count($count)->create(['is_active' => true])->all(), new TranslationProviderLimits(50, 60_000));

    expect(array_map(fn (TranslationBatchRequest $chunk): int => count($chunk->items), $plan->chunks))->toBe([$count])
        ->and($plan->oversized)->toBe([])
        ->and($plan->provider)->toBe('scripted')
        ->and([$plan->maxItems, $plan->maxProviderVisibleChars])->toBe([50, 60_000]);
})->with([1, 39]);

it('cuts by the provider\'s item limit', function () {
    $plan = plannedFor(Category::factory()->count(11)->create(['is_active' => true])->all(), new TranslationProviderLimits(4, 60_000));

    expect(array_map(fn (TranslationBatchRequest $chunk): int => count($chunk->items), $plan->chunks))->toBe([4, 4, 3]);
    expectEveryChunkFits($plan);
});

it('cuts by the provider\'s character limit, and by both at once', function () {
    // Long descriptions, some 600 characters: two of them fill a request by characters,
    // while three short names fit by characters and close it by count.
    $long = RatingGroup::factory()->count(4)->create(['is_active' => true, 'description' => str_repeat('Eine lange Beschreibung. ', 24)])->all();
    $short = Category::factory()->count(6)->create(['is_active' => true])->all();

    $plan = plannedFor([...$long, ...$short], function (TranslationBatchRequest $request): TranslationProviderLimits {
        $prompts = app(TranslationPromptBuilder::class);

        $longest = max(array_map($prompts->itemLength(...), array_slice($request->items, 0, 4)));

        return new TranslationProviderLimits(3, $prompts->envelopeLength($request) + 2 * $longest + 1);
    });

    expect(array_map(fn (TranslationBatchRequest $chunk): int => count($chunk->items), $plan->chunks))->toBe([2, 2, 3, 3]);
    expectEveryChunkFits($plan);
});

it('sets an item too large for any request apart, whole, and plans the rest', function () {
    $huge = RatingGroup::factory()->create(['is_active' => true, 'description' => str_repeat('Sehr lang. ', 80)]);
    $short = Category::factory()->count(2)->create(['is_active' => true])->all();

    // Room for any one short text, not for the long one.
    $plan = plannedFor([$huge, ...$short], function (TranslationBatchRequest $request): TranslationProviderLimits {
        $prompts = app(TranslationPromptBuilder::class);

        return new TranslationProviderLimits(50, $prompts->envelopeLength($request) + max($prompts->itemLength($request->items[1]), $prompts->itemLength($request->items[2])) + 10);
    });

    expect(array_map(fn ($item) => $item->id, $plan->oversized))->toBe([plannerUnit($huge)])
        ->and(array_merge(...array_map(fn (TranslationBatchRequest $chunk): array => $chunk->itemIds(), $plan->chunks)))->toBe(array_map(plannerUnit(...), $short));
    expectEveryChunkFits($plan);
});

it('plans more than one engine call accepts, in chunks — the 500-item limit is per call', function () {
    $plan = plannedFor(Category::factory()->count(120)->create(['is_active' => true])->all(), new TranslationProviderLimits(50, 60_000));

    expect(array_map(fn (TranslationBatchRequest $chunk): int => count($chunk->items), $plan->chunks))->toBe([50, 50, 20]);
});

it('knows no limits of its own: they are the provider\'s, applied by the engine\'s chunker', function () {
    $source = phpSourceWithoutComments('app/Support/Translations/Generation/ProjectTranslationGenerationPlanner.php');

    expect($source)->toContain('TranslationBatchChunker')
        ->toContain('->limits()')
        ->not->toMatch('/\b(50|60_?000|500_?000)\b/');
});
