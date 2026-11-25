<?php

use App\Exceptions\Languages\LanguageDetectionFailed;
use App\Services\Languages\OpenAiLanguageDetector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The languages of a batch of texts as OpenAI reads them, through the Translation AI account; nothing stored on
 * OpenAI's side, and its answer never trusted beyond the languages the site installs.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'translation.providers.openai.api_key' => 'test-key',
        'translation.providers.openai.model' => 'gpt-test',
        'translation.providers.openai.base_url' => 'https://api.openai.test/v1',
    ]);
});

/**
 * @param  list<array{id: string, language: string, confidence: float}>  $results
 */
function openAiLanguages(array $results): array
{
    return ['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['results' => $results])]]]]];
}

it('reads the language of each text, none for a text that tells none', function () {
    Http::fake(['api.openai.test/*' => Http::response(openAiLanguages([
        ['id' => '7', 'language' => 'ru', 'confidence' => 0.98],
        ['id' => '9', 'language' => 'und', 'confidence' => 0.9],
        ['id' => '11', 'language' => 'other', 'confidence' => 0.7],
    ]))]);

    $answers = app(OpenAiLanguageDetector::class)->detect([7 => 'Красивая фотография', 9 => 'Anna', 11 => 'Hyvää päivää']);

    expect($answers)->toBe([
        7 => ['language' => 'ru', 'confidence' => 0.98],
        9 => ['language' => null, 'confidence' => 0.9],
        11 => ['language' => null, 'confidence' => 0.7],
    ]);
});

it('asks once for the batch, through the Translation AI account, keeping nothing on OpenAI\'s side', function () {
    Http::fake(['api.openai.test/*' => Http::response(openAiLanguages([['id' => '1', 'language' => 'de', 'confidence' => 1]]))]);

    app(OpenAiLanguageDetector::class)->detect([1 => 'Schönes Foto']);

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $schema = $request['text']['format']['schema']['properties']['results']['items']['properties'];

        return $request->url() === 'https://api.openai.test/v1/responses'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['model'] === 'gpt-test'
            && $request['store'] === false
            && $request['input'][0]['content'][0]['text'] === '{"id":"1","text":"Schönes Foto"}'
            && in_array('uk', $schema['language']['enum'], true)
            && in_array('und', $schema['language']['enum'], true);
    });
});

it('ignores an answer for a text it was not asked about, or with a language outside the list', function () {
    Http::fake(['api.openai.test/*' => Http::response(openAiLanguages([
        ['id' => '1', 'language' => 'xx', 'confidence' => 1],
        ['id' => '2', 'language' => 'de', 'confidence' => 1],
        ['id' => '3', 'language' => 'fr', 'confidence' => 5],
    ]))]);

    expect(app(OpenAiLanguageDetector::class)->detect([1 => 'a', 3 => 'b']))
        ->toBe([1 => ['language' => null, 'confidence' => 1.0], 3 => ['language' => 'fr', 'confidence' => 1.0]]);
});

it('takes an answer only for an id exactly as it was sent', function () {
    Http::fake(['api.openai.test/*' => Http::response(openAiLanguages([
        ['id' => '7.9', 'language' => 'de', 'confidence' => 1],
        ['id' => ' 7', 'language' => 'de', 'confidence' => 1],
        ['id' => '07', 'language' => 'de', 'confidence' => 1],
        ['id' => '8', 'language' => 'fr', 'confidence' => 1],
    ]))]);

    expect(app(OpenAiLanguageDetector::class)->detect([7 => 'a', 8 => 'b']))->toBe([8 => ['language' => 'fr', 'confidence' => 1.0]]);
});

it('fails a batch as unavailable when OpenAI cannot be reached or is overloaded', function (Closure $response) {
    Http::fake(['api.openai.test/*' => $response]);

    try {
        app(OpenAiLanguageDetector::class)->detect([1 => 'Schönes Foto']);
        $this->fail('No failure');
    } catch (LanguageDetectionFailed $failure) {
        expect($failure->unavailable())->toBeTrue();
    }
})->with([
    'a server error' => [fn () => Http::response('', 500)],
    'too many requests' => [fn () => Http::response('', 429)],
    'no connection' => [fn () => throw new ConnectionException('timed out')],
]);

it('fails a batch as unusable for a refusal or an answer of another shape', function (array $body, int $status) {
    Http::fake(['api.openai.test/*' => Http::response($body, $status)]);

    try {
        app(OpenAiLanguageDetector::class)->detect([1 => 'Schönes Foto']);
        $this->fail('No failure');
    } catch (LanguageDetectionFailed $failure) {
        expect($failure->unavailable())->toBeFalse()
            ->and($failure->getMessage())->toBe(LanguageDetectionFailed::UNUSABLE);
    }
})->with([
    'a refusal' => [['output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'No.']]]]], 200],
    'no output text' => [['output' => []], 200],
    'text that is not the schema' => [['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'ru']]]]], 200],
    'a bad request' => [['error' => ['message' => 'bad']], 400],
]);

it('is not set up without the Translation AI key, and asks nothing then', function () {
    config(['translation.providers.openai.api_key' => '']);

    expect(app(OpenAiLanguageDetector::class)->configured())->toBeFalse()
        ->and(fn () => app(OpenAiLanguageDetector::class)->detect([1 => 'Schönes Foto']))->toThrow(LanguageDetectionFailed::class);

    Http::assertNothingSent();
});
