<?php

use App\Actions\Translations\GenerateProjectTranslationSuggestionAction;
use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotSuggestTranslationException;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\TranslationAiProviderCall;
use App\Models\User;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationBatchResult;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Data\TranslationItemResult;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Data\TranslationProviderResponse;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\TranslationEngine\Providers\OpenAiTranslationProvider;
use App\Support\TranslationEngine\TranslationService;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\Telemetry\TranslationAiCallRecorder;
use App\Support\Translations\Telemetry\TranslationAiConsumer;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Translation AI telemetry: every request a provider was actually sent leaves
 * one row of safe metadata in translation_ai_provider_calls — recorded by the
 * consumer once the engine has returned, never by the engine — and nothing
 * about recording it can change, repeat or withhold a translation.
 */

/** @return Collection<int, TranslationAiProviderCall> */
function recordedAiCalls(): Collection
{
    return TranslationAiProviderCall::query()->orderBy('id')->get();
}

/** An AI suggestion as Translation Center asks for one; a refusal is as good an outcome here. */
function suggestTranslationForTelemetry(string $unit, string $locale, ?User $admin = null): ?string
{
    try {
        return app(GenerateProjectTranslationSuggestionAction::class)
            ->handle($admin ?? User::factory()->admin()->create(), $unit, $locale)->text;
    } catch (CannotSuggestTranslationException) {
        return null;
    }
}

/**
 * Makes every write of a telemetry row fail at the database itself: a failing
 * statement runs on the same connection first, so on PostgreSQL the
 * transaction it runs in is aborted — the case the recorder's savepoint
 * exists for.
 */
function failTranslationAiTelemetryWrites(): void
{
    DB::connection()->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
        if (str_starts_with(strtolower(ltrim($query)), 'insert') && str_contains($query, 'translation_ai_provider_calls')) {
            $connection->getPdo()->exec('SELECT * FROM translation_ai_telemetry_store_that_is_down');
        }
    });
}

/** A provider answering every item with its own text, except the item to break, answered over its limit. */
function providerBreakingOneItem(string $broken): ScriptedTranslationProvider
{
    return new ScriptedTranslationProvider(
        static fn (TranslationBatchRequest $request): TranslationProviderResponse => scriptedTranslationResponse($request, array_map(
            static fn (TranslationItem $item): array => ['id' => $item->id, 'text' => $item->id === $broken ? str_repeat('x', 200) : "Übersetzt {$item->id}"],
            $request->items,
        )),
    );
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    Http::preventStrayRequests();
    [$this->target] = twoTranslatedLocales();
    $this->category = untranslatedCategory('Grandmother\'s secret dumplings');
    $this->unit = "categories:{$this->category->id}:name";
});

// =============================================================================
// One row per provider call
// =============================================================================

it('records one successful provider call as one row of metadata', function () {
    useScriptedTranslationProvider(answeringTranslationProvider(['Бабушкины пельмени']));
    Carbon::setTestNow('2026-10-11 09:41:07');

    expect(suggestTranslationForTelemetry($this->unit, $this->target))->toBe('Бабушкины пельмени');

    $calls = recordedAiCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->only([
            'provider', 'model', 'consumer', 'target_locale', 'provider_target_locale', 'error_code', 'http_status',
            'external_request_id', 'latency_ms', 'item_count', 'succeeded_item_count', 'failed_item_count', 'item_failures',
            'input_tokens', 'output_tokens', 'total_tokens',
        ]))->toBe([
            'provider' => 'scripted',
            'model' => 'scripted-model',
            'consumer' => 'interactive_ai',
            'target_locale' => $this->target,
            'provider_target_locale' => $this->target,
            'error_code' => null,
            'http_status' => 200,
            'external_request_id' => 'scripted-request',
            'latency_ms' => 1,
            'item_count' => 1,
            'succeeded_item_count' => 1,
            'failed_item_count' => 0,
            'item_failures' => null,
            'input_tokens' => 10,
            'output_tokens' => 5,
            'total_tokens' => 15,
        ])
        ->and($calls[0]->status)->toBe(TranslationStatus::Success)
        ->and($calls[0]->completed_at->toDateTimeString())->toBe('2026-10-11 09:41:07');
});

it('records a real OpenAI call by the model the provider reported, priced as the dated snapshot it is', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => openAiTranslatingResponder()]);

    suggestTranslationForTelemetry($this->unit, $this->target);

    $call = recordedAiCalls()->sole();

    // openAiResponseBody(): model gpt-6-luna-2026-09-01, 120 input and 30 output tokens.
    expect($call->only(['provider', 'model', 'external_request_id', 'http_status', 'input_tokens', 'output_tokens', 'total_tokens']))->toBe([
        'provider' => 'openai',
        'model' => 'gpt-6-luna-2026-09-01',
        'external_request_id' => 'resp_translation_test',
        'http_status' => 200,
        'input_tokens' => 120,
        'output_tokens' => 30,
        'total_tokens' => 150,
    ])
        // 120 × $0.10 / 1M + 30 × $0.50 / 1M
        ->and($call->estimated_cost_usd)->toBe('0.000027000000')
        ->and($call->input_usd_per_million_tokens)->toBe('0.100000')
        ->and($call->output_usd_per_million_tokens)->toBe('0.500000');
});

it('records each physical request of a batch the engine cut into several', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $request = translationBatch(translationItems(104));

    $result = app(TranslationService::class)->translate($request);
    app(TranslationAiCallRecorder::class)->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');

    $calls = recordedAiCalls();

    expect($result->calls)->toHaveCount(3)
        ->and($calls)->toHaveCount(3)
        ->and($calls->pluck('item_count')->all())->toBe([50, 50, 4])
        ->and($calls->pluck('succeeded_item_count')->all())->toBe([50, 50, 4])
        ->and($calls->sum('item_count'))->toBe(104);
});

it('dates each request of a batch by when it completed, the later ones last', function () {
    Carbon::setTestNow('2026-10-11 12:00:00');
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        static fn (TranslationBatchRequest $request, int $call): TranslationProviderResponse => new TranslationProviderResponse(
            array_map(static fn (TranslationItem $item): array => ['id' => $item->id, 'text' => 'Übersetzt'], $request->items),
            TranslationProviderCall::succeeded('scripted', 'scripted-model', $request->itemIds(), 30_000),
        ),
        new TranslationProviderLimits(1, 60_000),
    ));
    $request = translationBatch(translationItems(3));

    app(TranslationAiCallRecorder::class)->record($request, app(TranslationService::class)->translate($request), TranslationAiConsumer::GenerateMissing, 'de');

    expect(recordedAiCalls()->map(fn (TranslationAiProviderCall $call): string => $call->completed_at->toTimeString())->all())
        ->toBe(['11:59:00', '11:59:30', '12:00:00']);
});

// =============================================================================
// Provider success and item success are different things
// =============================================================================

it('keeps a call the provider answered successful when one of its items breaks a constraint', function () {
    useScriptedTranslationProvider(providerBreakingOneItem('item:4'));
    $request = translationBatch(translationItems(10, ['maxLength' => 80]));

    $result = app(TranslationService::class)->translate($request);
    app(TranslationAiCallRecorder::class)->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');

    $call = recordedAiCalls()->sole();

    expect($call->status)->toBe(TranslationStatus::Success)
        ->and($call->error_code)->toBeNull()
        ->and($call->item_count)->toBe(10)
        ->and($call->succeeded_item_count)->toBe(9)
        ->and($call->failed_item_count)->toBe(1)
        ->and($call->item_failures)->toBe(['constraint_violation' => 1]);
});

it('counts an item a successful call left untranslated as a failed item, under its own code', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        static fn (TranslationBatchRequest $request): TranslationProviderResponse => scriptedTranslationResponse($request, [
            ['id' => 'item:1', 'text' => 'Eins'],
            ['id' => 'item:2', 'text' => str_repeat('x', 200)],
        ]),
    ));
    $request = translationBatch(translationItems(3, ['maxLength' => 80]));

    app(TranslationAiCallRecorder::class)->record($request, app(TranslationService::class)->translate($request), TranslationAiConsumer::GenerateMissing, 'de');

    expect(recordedAiCalls()->sole()->only(['succeeded_item_count', 'failed_item_count', 'item_failures', 'error_code']))->toBe([
        'succeeded_item_count' => 1,
        'failed_item_count' => 2,
        'item_failures' => ['constraint_violation' => 1, 'invalid_provider_response' => 1],
        'error_code' => null,
    ]);
});

it('records a failed provider call with its code, failing every item it carried', function (Closure $fake, string $code, ?int $httpStatus, ?string $requestId) {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => $fake()]);

    expect(suggestTranslationForTelemetry($this->unit, $this->target))->toBeNull();

    $call = recordedAiCalls()->sole();

    expect($call->status)->toBe(TranslationStatus::Failed)
        ->and($call->only(['error_code', 'http_status', 'external_request_id', 'item_count', 'succeeded_item_count', 'failed_item_count', 'item_failures']))->toBe([
            'error_code' => $code,
            'http_status' => $httpStatus,
            'external_request_id' => $requestId,
            'item_count' => 1,
            'succeeded_item_count' => 0,
            'failed_item_count' => 1,
            'item_failures' => [$code => 1],
        ]);
})->with([
    'timeout' => [fn () => Http::failedConnection('cURL error 28: Operation timed out'), 'provider_timeout', null, null],
    'rate limited' => [fn () => Http::response(['error' => ['message' => 'Slow down']], 429, ['x-request-id' => 'req_rate_limited']), 'rate_limited', 429, 'req_rate_limited'],
    'credentials refused' => [fn () => Http::response(['error' => ['message' => 'Bad key']], 401), 'authentication_failed', 401, null],
    'unusable answer' => [fn () => Http::response(openAiResponseBody('not json')), 'invalid_provider_response', 200, 'resp_translation_test'],
]);

// =============================================================================
// Tokens and cost: unknown is null, never zero
// =============================================================================

it('keeps token usage the provider did not report as null, and the cost of such a call unknown', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => static fn ($request) => Http::response(openAiTranslationsBody(
        array_map(static fn (array $item): array => ['id' => $item['id'], 'text' => 'Übersetzt'], sentTranslationPayload($request)['items']),
        ['usage' => null],
    ))]);

    suggestTranslationForTelemetry($this->unit, $this->target);

    $call = recordedAiCalls()->sole();

    expect($call->only(['input_tokens', 'output_tokens', 'total_tokens', 'estimated_cost_usd']))->toBe([
        'input_tokens' => null,
        'output_tokens' => null,
        'total_tokens' => null,
        'estimated_cost_usd' => null,
    ])
        // The model's rates were known; the tokens were not — so neither is the cost.
        ->and($call->input_usd_per_million_tokens)->toBe('0.100000');
});

it('does not price a timeout as a free call', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => Http::failedConnection('cURL error 28: Operation timed out')]);

    suggestTranslationForTelemetry($this->unit, $this->target);

    expect(recordedAiCalls()->sole()->estimated_cost_usd)->toBeNull();
});

it('leaves the cost of a model without configured rates unknown, never $0', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => static fn ($request) => Http::response(openAiTranslationsBody(
        array_map(static fn (array $item): array => ['id' => $item['id'], 'text' => 'Übersetzt'], sentTranslationPayload($request)['items']),
        ['model' => 'gpt-6-luna-mini-2026-09-01'],
    ))]);

    suggestTranslationForTelemetry($this->unit, $this->target);

    expect(recordedAiCalls()->sole()->only(['model', 'input_tokens', 'estimated_cost_usd', 'input_usd_per_million_tokens', 'output_usd_per_million_tokens']))->toBe([
        'model' => 'gpt-6-luna-mini-2026-09-01',
        'input_tokens' => 120,
        'estimated_cost_usd' => null,
        'input_usd_per_million_tokens' => null,
        'output_usd_per_million_tokens' => null,
    ]);
});

it('keeps a recorded cost as it was when the configured rates change', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => openAiTranslatingResponder()]);
    suggestTranslationForTelemetry($this->unit, $this->target);

    config(['translation.telemetry.pricing.openai' => ['gpt-6-luna' => ['input' => '1.00', 'output' => '5.00']]]);
    suggestTranslationForTelemetry($this->unit, $this->target);

    $calls = recordedAiCalls();

    expect($calls->pluck('estimated_cost_usd')->all())->toBe(['0.000027000000', '0.000270000000'])
        ->and($calls->pluck('input_usd_per_million_tokens')->all())->toBe(['0.100000', '1.000000'])
        ->and($calls->first()->fresh()->estimated_cost_usd)->toBe('0.000027000000');
});

// =============================================================================
// Only what reached a provider
// =============================================================================

it('records nothing when there is no API key, since nothing was sent', function () {
    configureOpenAiTranslation(['api_key' => null]);
    Http::fake();

    expect(suggestTranslationForTelemetry($this->unit, $this->target))->toBeNull()
        ->and(recordedAiCalls())->toHaveCount(0);

    Http::assertNothingSent();
});

it('counts no item the engine set apart as too large to send', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(50, 2_000)));
    $request = translationBatch([
        translationItem(['id' => 'small']),
        translationItem(['id' => 'huge', 'sourceText' => str_repeat('a', 5_000), 'maxLength' => null]),
    ]);

    $result = app(TranslationService::class)->translate($request);
    app(TranslationAiCallRecorder::class)->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');

    expect($result->item('huge')->errorCode)->toBe(TranslationErrorCode::RequestTooLarge)
        ->and($provider->chunkSizes())->toBe([1])
        ->and(recordedAiCalls()->sole()->only(['item_count', 'succeeded_item_count', 'failed_item_count', 'item_failures']))->toBe([
            'item_count' => 1,
            'succeeded_item_count' => 1,
            'failed_item_count' => 0,
            'item_failures' => null,
        ]);
});

it('records no request a provider\'s own size guard refused before sending, but one the provider refused', function () {
    configureOpenAiTranslation(['max_items' => 1]);
    Http::fake(['*/responses' => Http::response(['error' => ['message' => 'Too large']], 413)]);
    $request = translationBatch(translationItems(2));
    $provider = app(OpenAiTranslationProvider::class, ['name' => 'openai']);

    $guarded = new TranslationBatchResult(
        [TranslationItemResult::failed('item:1', TranslationErrorCode::RequestTooLarge), TranslationItemResult::failed('item:2', TranslationErrorCode::RequestTooLarge)],
        [rescue(fn () => $provider->translateBatch($request), fn (TranslationProviderException $exception) => $exception->call, report: false)],
    );
    $refused = app(TranslationService::class)->translate(translationBatch());

    app(TranslationAiCallRecorder::class)->record($request, $guarded, TranslationAiConsumer::GenerateMissing, 'de');
    app(TranslationAiCallRecorder::class)->record(translationBatch(), $refused, TranslationAiConsumer::GenerateMissing, 'de');

    Http::assertSentCount(1);
    expect($guarded->calls[0]->errorCode)->toBe(TranslationErrorCode::RequestTooLarge)
        ->and(recordedAiCalls()->sole()->only(['error_code', 'http_status']))->toBe(['error_code' => 'request_too_large', 'http_status' => 413]);
});

it('records a call once, however often its result is handed over', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(2, 60_000)));
    $request = translationBatch(translationItems(3));
    $result = app(TranslationService::class)->translate($request);
    $recorder = app(TranslationAiCallRecorder::class);

    $recorder->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');
    $recorder->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');
    app(TranslationAiCallRecorder::class)->record($request, $result, TranslationAiConsumer::InteractiveAi, 'de');

    expect(recordedAiCalls())->toHaveCount(2)
        ->and(recordedAiCalls()->pluck('consumer')->unique()->all())->toBe(['generate_missing']);
});

// =============================================================================
// The two consumers
// =============================================================================

describe('Generate missing', function () {
    beforeEach(function () {
        Queue::fake();
        $this->admin = User::factory()->admin()->create();

        foreach (app(ProjectTranslationCatalog::class)->units() as $unit) {
            if ($unit->requiresTranslation() && $unit->id !== $this->unit) {
                foreach (['sr', $this->target] as $locale) {
                    if ($unit->translation($locale) === null) {
                        app(UpdateProjectTranslationAction::class)->handle($this->admin, $unit->id, $locale, 'Übersetzt');
                    }
                }
            }
        }

        Category::factory()->create(['name' => 'Birds', 'name_translations' => null, 'is_active' => true]);
    });

    it('records each chunk\'s call as generate_missing, by the installed language and the tag the provider was asked for', function () {
        useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(1, 60_000)));
        startTranslationGeneration($this->admin, 'sr');

        expect(runTranslationGenerationJobs())->toBe(2);

        $calls = recordedAiCalls();

        expect($calls)->toHaveCount(2)
            ->and($calls->pluck('consumer')->unique()->all())->toBe(['generate_missing'])
            ->and($calls->pluck('target_locale')->unique()->all())->toBe(['sr'])
            ->and($calls->pluck('provider_target_locale')->unique()->all())->toBe(['sr-Cyrl'])
            ->and($calls->pluck('item_count')->all())->toBe([1, 1]);
    });

    it('records nothing more when the same job is delivered again, since it sends nothing', function () {
        $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
        startTranslationGeneration($this->admin, $this->target);

        runTranslationGenerationJobs();
        runTranslationGenerationJobs();
        runTranslationGenerationJobs();

        expect($provider->received)->toHaveCount(1)
            ->and(recordedAiCalls())->toHaveCount(1)
            ->and(recordedAiCalls()->sole()->item_count)->toBe(2);
    });

    it('still stores what a chunk produced when its call cannot be recorded, and sends it once', function () {
        $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
        failTranslationAiTelemetryWrites();
        startTranslationGeneration($this->admin, $this->target);

        runTranslationGenerationJobs();

        $generation = translationGenerationOf($this->admin, $this->target);

        expect($provider->received)->toHaveCount(1)
            ->and($generation['status'])->toBe('completed')
            ->and($generation['counts'])->toMatchArray(['ready' => 2, 'failed' => 0])
            ->and(translationGenerationItem($generation, $this->unit)['status'])->toBe('ready')
            ->and(translationGenerationItem($generation, $this->unit)['text'])->toEndWith("] Grandmother's secret dumplings");
    });
});

// =============================================================================
// A telemetry failure never touches the translation
// =============================================================================

it('returns the suggestion unchanged when its call cannot be recorded, and asks the provider once', function () {
    $provider = useScriptedTranslationProvider(answeringTranslationProvider(['Бабушкины пельмени']));
    failTranslationAiTelemetryWrites();
    Log::spy();

    expect(suggestTranslationForTelemetry($this->unit, $this->target))->toBe('Бабушкины пельмени')
        ->and($provider->received)->toHaveCount(1);

    // The transaction the test runs in survived the failed write: PostgreSQL would otherwise refuse this.
    expect(TranslationAiProviderCall::query()->count())->toBe(0)
        ->and($this->category->fresh()->name_translations)->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $event, array $context): bool => $event === 'translation.telemetry_write_failed'
        && $context['consumer'] === 'interactive_ai'
        && $context['call_count'] === 1
        && $context['exception_class'] === PDOException::class
        && ! str_contains(json_encode($context), 'translation_ai_telemetry_store_that_is_down'));
});

it('never sends again for a call that could not be recorded', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => openAiTranslatingResponder()]);
    failTranslationAiTelemetryWrites();
    $request = translationBatch(translationItems(2));
    $result = app(TranslationService::class)->translate($request);
    $recorder = app(TranslationAiCallRecorder::class);

    $recorder->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');
    $recorder->record($request, $result, TranslationAiConsumer::GenerateMissing, 'de');

    Http::assertSentCount(1);
    expect(recordedAiCalls())->toHaveCount(0);
});

// =============================================================================
// Nothing but metadata
// =============================================================================

it('stores no text, prompt, context, glossary, credential or item id', function () {
    configureOpenAiTranslation();
    Http::fake(['*/responses' => openAiTranslatingResponder(static fn (array $item): string => 'Тайные бабушкины пельмени')]);
    $request = translationBatch([translationItem([
        'id' => 'categories:4242:name',
        'sourceText' => 'Grandmother\'s secret dumplings',
        'context' => 'Only for the family recipe book',
        'existingTranslations' => ['fr' => 'Raviolis secrets de grand-mère'],
    ])], glossary: ['RateGuru' => 'RateGuru-Glossar']);

    $result = app(TranslationService::class)->translate($request);
    app(TranslationAiCallRecorder::class)->record($request, $result, TranslationAiConsumer::InteractiveAi, 'de');

    $stored = json_encode(DB::table('translation_ai_provider_calls')->get()->all(), JSON_UNESCAPED_UNICODE);

    expect($result->item('categories:4242:name')->text)->toBe('Тайные бабушкины пельмени')
        ->and(recordedAiCalls())->toHaveCount(1);

    foreach (['secret dumplings', 'Тайные', 'family recipe', 'grand-mère', 'Glossar', 'categories:4242', TRANSLATION_TEST_API_KEY, 'instructions', 'Translate'] as $forbidden) {
        expect($stored)->not->toContain($forbidden);
    }
});

it('has no column a text could be stored in', function () {
    expect(Schema::getColumnListing('translation_ai_provider_calls'))->toEqualCanonicalizing([
        'id', 'completed_at', 'provider', 'model', 'consumer', 'target_locale', 'provider_target_locale',
        'status', 'error_code', 'http_status', 'external_request_id', 'latency_ms',
        'item_count', 'succeeded_item_count', 'failed_item_count', 'item_failures',
        'input_tokens', 'output_tokens', 'total_tokens',
        'estimated_cost_usd', 'input_usd_per_million_tokens', 'output_usd_per_million_tokens',
        'created_at',
    ]);
});

it('keeps a provider\'s model name and request id only when they look like identifiers', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        static fn (TranslationBatchRequest $request): TranslationProviderResponse => new TranslationProviderResponse(
            [['id' => $request->items[0]->id, 'text' => 'Übersetzt']],
            TranslationProviderCall::succeeded('scripted', 'Here is your translation: Hunde', $request->itemIds(), 5, "req_1\nInjected: Hunde", 200),
        ),
    ));
    $request = translationBatch();

    app(TranslationAiCallRecorder::class)->record($request, app(TranslationService::class)->translate($request), TranslationAiConsumer::InteractiveAi, 'de');

    expect(recordedAiCalls()->sole()->only(['model', 'external_request_id']))->toBe(['model' => 'unknown', 'external_request_id' => null]);
});
