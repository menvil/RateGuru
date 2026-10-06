<?php

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\TranslationEngine\TranslationService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * TranslationService against a scripted provider: what a consumer gets back
 * for a batch, whatever the provider does with the requests it is sent.
 */

// =============================================================================
// One contract for one item and for many
// =============================================================================

it('is what a consumer type-hints, with no provider in sight', function () {
    $consumer = new class(app(TranslationService::class))
    {
        public function __construct(public readonly TranslationService $translations) {}
    };

    expect($consumer->translations)->toBeInstanceOf(TranslationService::class)
        ->and(app(TranslationService::class))->toBe($consumer->translations);
});

it('sends one item and 39 items down the same path, one provider request each', function (int $count) {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    $result = app(TranslationService::class)->translate(translationBatch(translationItems($count)));

    expect($provider->chunkSizes())->toBe([$count])
        ->and($result->items)->toHaveCount($count)
        ->and($result->successful())->toHaveCount($count)
        ->and($result->calls)->toHaveCount(1)
        ->and($result->items[0]->text)->toBe('[de] Text number 1');
})->with([1, 39]);

it('translates a batch of 500 items, cut into provider requests the consumer never sees', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(500)));

    expect($provider->chunkSizes())->toBe(array_fill(0, 10, 50))
        ->and($result->successful())->toHaveCount(500)
        ->and($result->calls)->toHaveCount(10);
});

// =============================================================================
// The logical batch
// =============================================================================

it('refuses a batch of more than 500 items before choosing a provider', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    expect(fn () => app(TranslationService::class)->translate(translationBatch(translationItems(501))))
        ->toThrow(InvalidTranslationRequestException::class, 'holds 501 items; one batch holds at most 500');

    expect($provider->received)->toBe([]);
});

it('refuses a batch beyond the provider-visible character ceiling before choosing a provider', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    // Nine texts of 59,000 characters: each would fit a provider request on
    // its own, together they exceed 500,000.
    $items = translationItems(9, ['sourceText' => str_repeat('a', 59_000), 'maxLength' => null]);

    expect(fn () => app(TranslationService::class)->translate(translationBatch($items)))
        ->toThrow(InvalidTranslationRequestException::class, 'provider-visible characters; one batch carries at most 500000');

    expect($provider->received)->toBe([]);
});

it('holds the logical limits from configuration', function () {
    config(['translation.max_batch_items' => 3]);
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    expect(fn () => app(TranslationService::class)->translate(translationBatch(translationItems(4))))
        ->toThrow(InvalidTranslationRequestException::class, 'one batch holds at most 3');
});

// =============================================================================
// Order and partial failure
// =============================================================================

it('returns results in the order the items were given, whatever order the provider answers in', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, array_reverse(array_map(
            fn ($item) => ['id' => $item->id, 'text' => "übersetzt {$item->id}"],
            $request->items,
        ))),
    ));

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(5)));

    expect(array_map(fn ($item) => $item->id, $result->items))->toBe(['item:1', 'item:2', 'item:3', 'item:4', 'item:5'])
        ->and($result->items[0]->text)->toBe('übersetzt item:1')
        ->and($result->items[4]->text)->toBe('übersetzt item:5');
});

it('keeps the translations of the requests around one that fails', function () {
    // The regression bulk generation depends on: 104 items are three requests
    // (50 + 50 + 4). The second fails; the first's translations survive, the
    // third is still sent and translated, and the result is in input order.
    $provider = useScriptedTranslationProvider(new ScriptedTranslationProvider(
        function (TranslationBatchRequest $request, int $call) {
            if ($call === 2) {
                throw new TranslationProviderException(
                    TranslationErrorCode::ProviderUnavailable,
                    TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 7, TranslationErrorCode::ProviderUnavailable, 'scripted-failure', 503),
                );
            }

            return scriptedTranslationResponse($request, array_map(
                fn ($item) => ['id' => $item->id, 'text' => "Übersetzung {$item->id}"],
                $request->items,
            ));
        },
    ));

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(104)));

    expect($provider->chunkSizes())->toBe([50, 50, 4])
        ->and($result->successful())->toHaveCount(54)
        ->and($result->failed())->toHaveCount(50)
        ->and(array_map(fn ($item) => $item->id, $result->items))->toBe(array_map(fn (int $n) => "item:{$n}", range(1, 104)));

    foreach ($result->items as $index => $item) {
        if ($index >= 50 && $index < 100) {
            expect($item->status)->toBe(TranslationStatus::Failed)
                ->and($item->text)->toBeNull()
                ->and($item->errorCode)->toBe(TranslationErrorCode::ProviderUnavailable)
                ->and($item->errorMessage)->toBe(TranslationErrorCode::ProviderUnavailable->message());
        } else {
            expect($item->status)->toBe(TranslationStatus::Success)
                ->and($item->text)->toBe("Übersetzung {$item->id}");
        }
    }

    expect($result->calls)->toHaveCount(3)
        ->and(array_map(fn ($call) => $call->status, $result->calls))->toBe([TranslationStatus::Success, TranslationStatus::Failed, TranslationStatus::Success])
        ->and($result->calls[1]->errorCode)->toBe(TranslationErrorCode::ProviderUnavailable)
        ->and($result->calls[1]->itemIds)->toBe(array_map(fn (int $n) => "item:{$n}", range(51, 100)))
        ->and($result->calls[2]->itemIds)->toBe(['item:101', 'item:102', 'item:103', 'item:104']);
});

it('fails an item too large for any request as request_too_large, never sends it, and translates the rest', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(50, 5_000)));

    $items = translationItems(3);
    $huge = translationItem(['id' => 'post:1:body', 'sourceText' => str_repeat('Satz. ', 2_000), 'multiline' => true, 'maxLength' => null]);

    $result = app(TranslationService::class)->translate(translationBatch([$items[0], $huge, $items[1], $items[2]]));

    expect($result->item('post:1:body')->errorCode)->toBe(TranslationErrorCode::RequestTooLarge)
        ->and($result->items[1]->id)->toBe('post:1:body')
        ->and($result->successful())->toHaveCount(3)
        ->and(array_merge(...array_map(fn ($request) => $request->itemIds(), $provider->received)))->toBe(['item:1', 'item:2', 'item:3']);
});

// =============================================================================
// What came back is matched by id and never trusted
// =============================================================================

it('voids a whole response that returns an id the request did not carry, or one id twice', function (Closure $mangle) {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        function (TranslationBatchRequest $request, int $call) use ($mangle) {
            $translations = array_map(fn ($item) => ['id' => $item->id, 'text' => "Übersetzung {$item->id}"], $request->items);

            return scriptedTranslationResponse($request, $call === 1 ? $mangle($translations) : $translations);
        },
        new TranslationProviderLimits(3, 60_000),
    ));

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(5)));

    // The first request (three items) is unusable as a whole; the second is fine.
    expect(array_map(fn ($item) => $item->errorCode, array_slice($result->items, 0, 3)))
        ->toBe(array_fill(0, 3, TranslationErrorCode::InvalidProviderResponse))
        ->and($result->item('item:4')->text)->toBe('Übersetzung item:4')
        ->and($result->calls[0]->status)->toBe(TranslationStatus::Failed)
        ->and($result->calls[0]->errorCode)->toBe(TranslationErrorCode::InvalidProviderResponse)
        ->and($result->calls[1]->status)->toBe(TranslationStatus::Success);
})->with([
    'an unknown id' => fn (array $translations) => [...$translations, ['id' => 'item:999', 'text' => 'Fremd']],
    'a duplicate id' => fn (array $translations) => [...$translations, $translations[0]],
    'an unknown id in place of a known one' => fn (array $translations) => [['id' => 'ITEM:1', 'text' => 'x'], ...array_slice($translations, 1)],
]);

it('fails only the item a response leaves out', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, array_values(array_filter(
            array_map(fn ($item) => ['id' => $item->id, 'text' => "Übersetzung {$item->id}"], $request->items),
            fn (array $translation) => $translation['id'] !== 'item:2',
        ))),
    ));

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(3)));

    expect($result->item('item:2')->status)->toBe(TranslationStatus::Failed)
        ->and($result->item('item:2')->errorCode)->toBe(TranslationErrorCode::InvalidProviderResponse)
        ->and($result->item('item:1')->text)->toBe('Übersetzung item:1')
        ->and($result->item('item:3')->text)->toBe('Übersetzung item:3')
        ->and($result->calls[0]->status)->toBe(TranslationStatus::Success);
});

// =============================================================================
// Every translation is held to its item's constraints
// =============================================================================

it('holds every translation to its item\'s constraints', function (array $item, string $translation, ?string $expected, ?string $violation) {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, [['id' => $request->items[0]->id, 'text' => $translation]]),
    ));

    $result = app(TranslationService::class)->translate(translationBatch([translationItem($item)]))->items[0];

    if ($expected !== null) {
        expect($result->status)->toBe(TranslationStatus::Success)
            ->and($result->text)->toBe($expected)
            ->and($result->errorCode)->toBeNull();
    } else {
        expect($result->status)->toBe(TranslationStatus::Failed)
            ->and($result->text)->toBeNull()
            ->and($result->errorCode)->toBe(TranslationErrorCode::ConstraintViolation)
            ->and($result->errorMessage)->toContain($violation);
    }
})->with([
    'valid' => [['maxLength' => 80], 'Hunde', 'Hunde', null],
    'too long' => [['maxLength' => 5], 'Hündchen', null, 'The translation is 3 characters over the limit of 5.'],
    'a newline in a single-line item' => [['multiline' => false], "Hunde\nund Katzen", null, 'spans more than one line'],
    'a carriage return in a single-line item' => [['multiline' => false], "Hunde\rund Katzen", null, 'spans more than one line'],
    'a lost placeholder' => [
        ['sourceText' => 'Write to {contact_email}', 'placeholders' => ['{contact_email}']],
        'Schreiben Sie an {kontakt_email}', null, 'The translation lost {contact_email}.',
    ],
    'blank' => [[], '', null, 'The translation is blank.'],
    'only whitespace' => [[], "  \n\t ", null, 'The translation is blank.'],
    'valid multiline' => [
        ['multiline' => true, 'maxLength' => null, 'contentType' => 'post.body'],
        "Erster Absatz.\n\nZweiter Absatz.", "Erster Absatz.\n\nZweiter Absatz.", null,
    ],
    'emoji counted as code points, at the limit' => [['maxLength' => 6], 'Hallo👍', 'Hallo👍', null],
    'emoji counted as code points, one over' => [['maxLength' => 6], 'Hallo👍🏽', null, 'The translation is 1 characters over the limit of 6.'],
    'kept placeholders' => [
        ['sourceText' => 'Hello {name}, write to {contact_email}', 'placeholders' => ['{name}', '{contact_email}']],
        'Hallo {name}, schreiben Sie an {contact_email}', 'Hallo {name}, schreiben Sie an {contact_email}', null,
    ],
]);

it('reports every constraint a translation breaks', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, [['id' => $request->items[0]->id, 'text' => "Viel zu lang\nund ohne Platzhalter"]]),
    ));

    $result = app(TranslationService::class)->translate(translationBatch([translationItem([
        'sourceText' => 'Hi {name}',
        'placeholders' => ['{name}'],
        'maxLength' => 10,
    ])]))->items[0];

    expect($result->errorMessage)
        ->toContain('spans more than one line')
        ->toContain('over the limit of 10')
        ->toContain('lost {name}');
});

it('normalizes line endings and outer whitespace as Translation Center\'s save does, and nothing inside', function (bool $multiline, string $translation, string $expected) {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, [['id' => $request->items[0]->id, 'text' => $translation]]),
    ));

    $result = app(TranslationService::class)->translate(translationBatch([
        translationItem(['multiline' => $multiline, 'maxLength' => null]),
    ]))->items[0];

    expect($result->text)->toBe($expected);
})->with([
    'outer whitespace' => [false, "  Hunde \r\n", 'Hunde'],
    'line endings' => [true, "Eins\r\nZwei\rDrei\n", "Eins\nZwei\nDrei"],
    'inner spacing and Markdown kept' => [true, "  **Hunde**  und   Katzen\n\n- eins\n- zwei  ", "**Hunde**  und   Katzen\n\n- eins\n- zwei"],
]);

// =============================================================================
// What is said about a failure, and what is never said
// =============================================================================

it('logs a failed call with safe metadata only — never a text or a translation', function () {
    Log::spy();

    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => throw new TranslationProviderException(
            TranslationErrorCode::RateLimited,
            TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 12, TranslationErrorCode::RateLimited, 'req_rate_limited', 429),
        ),
    ));

    app(TranslationService::class)->translate(translationBatch([translationItem(['sourceText' => 'A visitor\'s private sentence'])]));

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $event, array $context): bool {
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);

        return $event === 'translation.provider_call_failed'
            && $context['provider'] === 'scripted'
            && $context['model'] === 'scripted-model'
            && $context['error_code'] === 'rate_limited'
            && $context['http_status'] === 429
            && $context['external_request_id'] === 'req_rate_limited'
            && $context['item_count'] === 1
            && $context['latency_ms'] === 12
            && ! str_contains($encoded, 'private sentence')
            && ! str_contains($encoded, 'categories:17:name');
    });
});

// =============================================================================
// It translates; it stores nothing
// =============================================================================

it('writes nothing to the database, queues nothing and dispatches nothing', function () {
    Queue::fake();
    Bus::fake();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $result = app(TranslationService::class)->translate(translationBatch(translationItems(60)));

    expect($result->successful())->toHaveCount(60)
        ->and($queries)->toBe(0);

    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
});
