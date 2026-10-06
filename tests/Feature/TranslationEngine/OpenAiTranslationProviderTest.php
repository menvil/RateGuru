<?php

use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationBatchResult;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\TranslationEngine\Providers\OpenAiTranslationProvider;
use App\Support\TranslationEngine\TranslationPromptBuilder;
use App\Support\TranslationEngine\TranslationService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The OpenAI provider against a faked Responses API. Nothing here reaches the
 * network: every request is answered by Http::fake(), and a request nothing
 * answers is an error rather than a real call.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    configureOpenAiTranslation();
});

const OPENAI_TEST_ENDPOINT = 'https://api.openai.com/v1/responses';

function openAiTranslate(?TranslationBatchRequest $batch = null): TranslationBatchResult
{
    return app(TranslationService::class)->translate($batch ?? translationBatch());
}

/** The provider's own refusal of one request, for asserting on what it carries. */
function openAiProviderFailure(?TranslationBatchRequest $batch = null): TranslationProviderException
{
    $provider = app()->make(OpenAiTranslationProvider::class, ['name' => 'openai']);

    try {
        $provider->translateBatch($batch ?? translationBatch());
    } catch (TranslationProviderException $exception) {
        return $exception;
    }

    throw new RuntimeException('the provider should have failed');
}

// =============================================================================
// The request
// =============================================================================

it('posts to the Responses API with the bearer key and JSON headers', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => openAiTranslatingResponder()]);

    openAiTranslate();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === OPENAI_TEST_ENDPOINT
        && $request->header('Authorization') === ['Bearer '.TRANSLATION_TEST_API_KEY]
        && $request->header('Accept') === ['application/json']
        && $request->header('Content-Type') === ['application/json']);
});

it('posts to the configured base URL', function (string $baseUrl) {
    configureOpenAiTranslation(['base_url' => $baseUrl]);
    Http::fake(['https://llm-gateway.internal.test/openai/v1/responses' => openAiTranslatingResponder()]);

    expect(openAiTranslate()->successful())->toHaveCount(1);
})->with(['https://llm-gateway.internal.test/openai/v1', 'https://llm-gateway.internal.test/openai/v1/']);

it('asks for the configured model, statelessly, with fixed instructions and the batch as input data', function () {
    configureOpenAiTranslation(['model' => 'gpt-test-configured']);
    Http::fake([OPENAI_TEST_ENDPOINT => openAiTranslatingResponder()]);

    $batch = translationBatch(translationItems(2));
    openAiTranslate($batch);

    $prompts = app(TranslationPromptBuilder::class);

    Http::assertSent(function (Request $request) use ($prompts, $batch) {
        $body = $request->data();

        expect(array_keys($body))->toBe(['model', 'store', 'instructions', 'input', 'text'])
            ->and($body['model'])->toBe('gpt-test-configured')
            ->and($body['store'])->toBeFalse()
            ->and($body['instructions'])->toBe($prompts->instructions())
            ->and($body['input'])->toBe([[
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => $prompts->input($batch)]],
            ]]);

        // Stateless: no conversation, no previous response, no tools.
        foreach (['conversation', 'previous_response_id', 'tools', 'tool_choice', 'background', 'prompt'] as $key) {
            expect($body)->not->toHaveKey($key);
        }

        return true;
    });
});

it('requires strict structured output against the translations schema', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => openAiTranslatingResponder()]);

    openAiTranslate();

    Http::assertSent(fn (Request $request) => $request->data()['text'] === [
        'format' => [
            'type' => 'json_schema',
            'name' => 'translation_batch',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'translations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'text' => ['type' => 'string'],
                            ],
                            'required' => ['id', 'text'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['translations'],
                'additionalProperties' => false,
            ],
        ],
    ]);
});

it('connects within 5 seconds, waits 45, follows no redirect and does not retry', function () {
    $options = null;

    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['error' => ['message' => 'upstream']], 500);
    });

    openAiTranslate();

    expect($options['connect_timeout'])->toEqual(5)
        ->and($options['timeout'])->toEqual(45)
        ->and($options['allow_redirects'])->toBeFalse();

    Http::assertSentCount(1);
});

it('never sends the data classification to the model', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => openAiTranslatingResponder()]);

    openAiTranslate();

    Http::assertSent(fn (Request $request) => ! str_contains($request->body(), 'public_content')
        && ! str_contains($request->body(), 'classification'));
});

// =============================================================================
// Successful responses
// =============================================================================

it('translates a batch of one item', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiTranslationsBody([['id' => 'categories:17:name', 'text' => 'Hunde']]))]);

    $result = openAiTranslate();

    expect($result->items)->toHaveCount(1)
        ->and($result->items[0]->id)->toBe('categories:17:name')
        ->and($result->items[0]->status)->toBe(TranslationStatus::Success)
        ->and($result->items[0]->text)->toBe('Hunde');
});

it('translates a batch of several items in one request', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => openAiTranslatingResponder()]);

    $result = openAiTranslate(translationBatch(translationItems(39)));

    Http::assertSentCount(1);

    expect($result->successful())->toHaveCount(39)
        ->and($result->item('item:39')->text)->toBe('[de] Text number 39');
});

it('restores input order when the model answers in another', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiTranslationsBody([
        ['id' => 'item:3', 'text' => 'Drei'],
        ['id' => 'item:1', 'text' => 'Eins'],
        ['id' => 'item:2', 'text' => 'Zwei'],
    ]))]);

    $result = openAiTranslate(translationBatch(translationItems(3)));

    expect(array_map(fn ($item) => [$item->id, $item->text], $result->items))
        ->toBe([['item:1', 'Eins'], ['item:2', 'Zwei'], ['item:3', 'Drei']]);
});

it('records the response id, the model that answered, usage and latency on the call', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(
        openAiTranslationsBody([['id' => 'categories:17:name', 'text' => 'Hunde']]),
        200,
        ['x-request-id' => 'req_header_id'],
    )]);

    $call = openAiTranslate()->calls[0];

    expect($call->provider)->toBe('openai')
        ->and($call->model)->toBe('gpt-6-luna-2026-09-01')
        ->and($call->externalRequestId)->toBe('resp_translation_test')
        ->and($call->itemIds)->toBe(['categories:17:name'])
        ->and($call->status)->toBe(TranslationStatus::Success)
        ->and($call->errorCode)->toBeNull()
        ->and($call->httpStatus)->toBe(200)
        ->and($call->inputTokens)->toBe(120)
        ->and($call->outputTokens)->toBe(30)
        ->and($call->totalTokens)->toBe(150)
        ->and($call->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('leaves token counts empty when the provider reports no usage', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiTranslationsBody(
        [['id' => 'categories:17:name', 'text' => 'Hunde']],
        ['usage' => null, 'model' => null],
    ))]);

    $call = openAiTranslate()->calls[0];

    expect($call->inputTokens)->toBeNull()
        ->and($call->outputTokens)->toBeNull()
        ->and($call->totalTokens)->toBeNull()
        ->and($call->model)->toBe('gpt-6-luna');
});

it('finds the structured output wherever it sits among the output items', function () {
    $structured = json_encode(['translations' => [['id' => 'categories:17:name', 'text' => 'Hunde']]]);

    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiResponseBody('', ['output' => [
        ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
        ['type' => 'an_output_item_of_another_kind', 'id' => 'other_1'],
        ['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'annotation_only'],
            ['type' => 'output_text', 'text' => $structured, 'annotations' => []],
        ]],
    ]]))]);

    expect(openAiTranslate()->items[0]->text)->toBe('Hunde');
});

// =============================================================================
// Failures the provider reports
// =============================================================================

it('classifies an HTTP failure safely', function (int $status, TranslationErrorCode $code) {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(
        ['error' => ['message' => 'Incorrect API key provided: '.TRANSLATION_TEST_API_KEY, 'type' => 'invalid_request_error']],
        $status,
        ['x-request-id' => 'req_failed'],
    )]);

    $result = openAiTranslate();

    expect($result->items[0]->status)->toBe(TranslationStatus::Failed)
        ->and($result->items[0]->errorCode)->toBe($code)
        ->and($result->items[0]->errorMessage)->toBe($code->message())
        ->and($result->calls[0]->status)->toBe(TranslationStatus::Failed)
        ->and($result->calls[0]->errorCode)->toBe($code)
        ->and($result->calls[0]->httpStatus)->toBe($status)
        ->and($result->calls[0]->externalRequestId)->toBe('req_failed');

    Http::assertSentCount(1);
})->with([
    'unauthorized' => [401, TranslationErrorCode::AuthenticationFailed],
    'forbidden' => [403, TranslationErrorCode::AuthenticationFailed],
    'rate limited' => [429, TranslationErrorCode::RateLimited],
    'server error' => [500, TranslationErrorCode::ProviderUnavailable],
    'unavailable' => [503, TranslationErrorCode::ProviderUnavailable],
    'gateway timeout' => [504, TranslationErrorCode::ProviderTimeout],
    'too large' => [413, TranslationErrorCode::RequestTooLarge],
    'bad request' => [400, TranslationErrorCode::ProviderRejectedRequest],
    'unknown model' => [404, TranslationErrorCode::ProviderRejectedRequest],
    'a redirect, not followed' => [307, TranslationErrorCode::ProviderUnavailable],
]);

it('classifies a connection failure and a timeout', function (string $message, TranslationErrorCode $code) {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::failedConnection($message)]);

    $result = openAiTranslate();

    expect($result->items[0]->errorCode)->toBe($code)
        ->and($result->calls[0]->httpStatus)->toBeNull();
})->with([
    'unresolvable host' => ['cURL error 6: Could not resolve host: api.openai.com', TranslationErrorCode::ProviderUnavailable],
    'connection refused' => ['cURL error 7: Failed to connect to api.openai.com port 443', TranslationErrorCode::ProviderUnavailable],
    'timed out' => ['cURL error 28: Operation timed out after 45001 milliseconds with 0 bytes received', TranslationErrorCode::ProviderTimeout],
]);

it('treats anything but the structured output as an invalid response', function (Closure $response) {
    Http::fake([OPENAI_TEST_ENDPOINT => $response]);

    $result = openAiTranslate(translationBatch(translationItems(2)));

    expect(array_map(fn ($item) => $item->errorCode, $result->items))
        ->toBe([TranslationErrorCode::InvalidProviderResponse, TranslationErrorCode::InvalidProviderResponse])
        ->and($result->calls[0]->errorCode)->toBe(TranslationErrorCode::InvalidProviderResponse);
})->with([
    'a body that is not JSON' => fn () => Http::response('<html>Bad gateway</html>', 200),
    'a JSON body that is not an object' => fn () => Http::response('"translations"', 200),
    'no output' => fn () => Http::response(openAiResponseBody('', ['output' => null])),
    'an empty output' => fn () => Http::response(openAiResponseBody('', ['output' => []])),
    'a message without output_text' => fn () => Http::response(openAiResponseBody('', ['output' => [
        ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'input_text', 'text' => '{}']]],
    ]])),
    'two output_text parts' => fn () => Http::response(openAiResponseBody('', ['output' => [
        ['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => '{"translations":'],
            ['type' => 'output_text', 'text' => '[]}'],
        ]],
    ]])),
    'output_text that is not JSON' => fn () => Http::response(openAiResponseBody('Hier sind die Übersetzungen: Hunde')),
    'JSON in a Markdown fence' => fn () => Http::response(openAiResponseBody(
        "```json\n".json_encode(['translations' => [['id' => 'item:1', 'text' => 'Eins'], ['id' => 'item:2', 'text' => 'Zwei']]])."\n```",
    )),
    'an incomplete response' => fn () => Http::response(openAiTranslationsBody(
        [['id' => 'item:1', 'text' => 'Eins'], ['id' => 'item:2', 'text' => 'Zwei']],
        ['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens']],
    )),
    'no translations key' => fn () => Http::response(openAiResponseBody('{"results":[]}')),
    'an extra top-level key' => fn () => Http::response(openAiResponseBody(json_encode([
        'translations' => [['id' => 'item:1', 'text' => 'Eins'], ['id' => 'item:2', 'text' => 'Zwei']],
        'notes' => 'translated carefully',
    ]))),
    'an extra key on an entry' => fn () => Http::response(openAiResponseBody(json_encode(['translations' => [
        ['id' => 'item:1', 'text' => 'Eins', 'confidence' => 0.9],
        ['id' => 'item:2', 'text' => 'Zwei'],
    ]]))),
    'a text that is not a string' => fn () => Http::response(openAiResponseBody(json_encode(['translations' => [
        ['id' => 'item:1', 'text' => ['Eins']],
        ['id' => 'item:2', 'text' => 'Zwei'],
    ]]))),
    'an id that is not a string' => fn () => Http::response(openAiResponseBody(json_encode(['translations' => [
        ['id' => 1, 'text' => 'Eins'],
        ['id' => 'item:2', 'text' => 'Zwei'],
    ]]))),
    'a duplicate returned id' => fn () => Http::response(openAiTranslationsBody([
        ['id' => 'item:1', 'text' => 'Eins'], ['id' => 'item:2', 'text' => 'Zwei'], ['id' => 'item:2', 'text' => 'Zwo'],
    ])),
    'an unknown returned id' => fn () => Http::response(openAiTranslationsBody([
        ['id' => 'item:1', 'text' => 'Eins'], ['id' => 'item:2', 'text' => 'Zwei'], ['id' => 'item:3', 'text' => 'Drei'],
    ])),
]);

it('keeps the response id and usage of a response it cannot use — the tokens were spent', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiResponseBody('not the structured output'))]);

    $call = openAiTranslate()->calls[0];

    expect($call->status)->toBe(TranslationStatus::Failed)
        ->and($call->externalRequestId)->toBe('resp_translation_test')
        ->and($call->totalTokens)->toBe(150);
});

it('reports a refusal as such', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiResponseBody('', ['output' => [
        ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'I can’t help with that.']]],
    ]]))]);

    $result = openAiTranslate();

    expect($result->items[0]->errorCode)->toBe(TranslationErrorCode::ProviderRefused)
        ->and($result->items[0]->errorMessage)->not->toContain('help with that');
});

it('fails only the item the model left out', function () {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiTranslationsBody([
        ['id' => 'item:1', 'text' => 'Eins'],
        ['id' => 'item:3', 'text' => 'Drei'],
    ]))]);

    $result = openAiTranslate(translationBatch(translationItems(3)));

    expect($result->item('item:2')->errorCode)->toBe(TranslationErrorCode::InvalidProviderResponse)
        ->and($result->item('item:1')->text)->toBe('Eins')
        ->and($result->item('item:3')->text)->toBe('Drei');
});

it('fails a blank translation', function (string $blank) {
    Http::fake([OPENAI_TEST_ENDPOINT => Http::response(openAiTranslationsBody([
        ['id' => 'item:1', 'text' => $blank],
        ['id' => 'item:2', 'text' => 'Zwei'],
    ]))]);

    $result = openAiTranslate(translationBatch(translationItems(2)));

    expect($result->item('item:1')->errorCode)->toBe(TranslationErrorCode::ConstraintViolation)
        ->and($result->item('item:1')->text)->toBeNull()
        ->and($result->item('item:2')->text)->toBe('Zwei');
})->with(['empty' => '', 'whitespace' => " \n "]);

it('keeps the translations of the requests around one OpenAI fails', function () {
    // End to end over HTTP: 104 items are three requests, and the second one
    // gets a 500. Its items fail; the first and third are translated.
    $requests = 0;
    $translate = openAiTranslatingResponder(fn (array $item) => "DE {$item['id']}");

    Http::fake([OPENAI_TEST_ENDPOINT => function (Request $request) use (&$requests, $translate) {
        return ++$requests === 2 ? Http::response(['error' => ['message' => 'overloaded']], 500) : $translate($request);
    }]);

    $result = openAiTranslate(translationBatch(translationItems(104)));

    Http::assertSentCount(3);

    expect($result->successful())->toHaveCount(54)
        ->and($result->failed())->toHaveCount(50)
        ->and(array_map(fn ($item) => $item->id, $result->items))->toBe(array_map(fn (int $n) => "item:{$n}", range(1, 104)))
        ->and($result->item('item:50')->text)->toBe('DE item:50')
        ->and($result->item('item:51')->errorCode)->toBe(TranslationErrorCode::ProviderUnavailable)
        ->and($result->item('item:100')->errorCode)->toBe(TranslationErrorCode::ProviderUnavailable)
        ->and($result->item('item:101')->text)->toBe('DE item:101')
        ->and(array_map(fn ($call) => $call->httpStatus, $result->calls))->toBe([200, 500, 200])
        ->and(array_map(fn ($call) => count($call->itemIds), $result->calls))->toBe([50, 50, 4]);
});

// =============================================================================
// The key never leaks
// =============================================================================

it('keeps the API key out of everything a failure produces', function (Closure $response) {
    Log::spy();
    Http::fake([OPENAI_TEST_ENDPOINT => $response]);

    $exception = openAiProviderFailure();

    expect($exception->getMessage())->not->toContain(TRANSLATION_TEST_API_KEY)
        ->and((string) $exception)->not->toContain(TRANSLATION_TEST_API_KEY)
        ->and(var_export($exception->call, true))->not->toContain(TRANSLATION_TEST_API_KEY)
        // The HTTP client's exception is dropped, not chained: its trace
        // holds the request headers.
        ->and($exception->getPrevious())->toBeNull();

    foreach ($exception->getTrace() as $frame) {
        expect(translationValueContains($frame['args'] ?? [], TRANSLATION_TEST_API_KEY))->toBeFalse();

        foreach ($frame['args'] ?? [] as $argument) {
            expect($argument)->not->toBeInstanceOf(PendingRequest::class)
                ->not->toBeInstanceOf(Response::class);
        }
    }

    // And through the service: the result and the log line.
    $result = openAiTranslate(translationBatch([translationItem(['sourceText' => 'A sentence nobody logs'])]));

    expect(var_export($result, true))->not->toContain(TRANSLATION_TEST_API_KEY)
        ->and(json_encode($result))->not->toContain(TRANSLATION_TEST_API_KEY);

    Log::shouldHaveReceived('warning')->withArgs(function (string $event, array $context): bool {
        $logged = json_encode($context, JSON_THROW_ON_ERROR);

        return ! str_contains($logged, TRANSLATION_TEST_API_KEY)
            && ! str_contains($logged, 'Bearer')
            && ! str_contains($logged, 'A sentence nobody logs')
            && ! str_contains($logged, 'Incorrect API key');
    });
})->with([
    '401 echoing the key' => fn () => Http::response(['error' => ['message' => 'Incorrect API key provided: '.TRANSLATION_TEST_API_KEY]], 401),
    '429' => fn () => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
    '500' => fn () => Http::response('Internal error', 500),
    'connection failure' => fn () => Http::failedConnection(),
    'timeout' => fn () => Http::failedConnection('cURL error 28: Operation timed out'),
    'malformed JSON' => fn () => Http::response('{"output": [', 200),
]);
