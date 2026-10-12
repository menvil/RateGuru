<?php

use App\Support\Translations\Telemetry\TranslationAiConfiguration;
use App\Support\Translations\Telemetry\TranslationAiConfigurationReader;
use Illuminate\Support\Facades\Http;

/**
 * The machine translation configured now, as Translation AI shows it: read
 * from configuration and the engine's own router — whether a public-content
 * batch would be accepted, whether there is an API key (never what it is),
 * the timeouts and the rates of the configured model — with nothing sent.
 */
function configuredTranslationAi(): TranslationAiConfiguration
{
    return app(TranslationAiConfigurationReader::class)->read();
}

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    config(['translation.default' => 'openai', 'translation.providers.openai.api_key' => 'test-key', 'translation.providers.openai.model' => 'gpt-6-luna']);
});

afterEach(fn () => Http::assertNothingSent());

it('reads a provider ready to translate, with its key, timeouts and rates, sending nothing', function () {
    expect(configuredTranslationAi())->toEqual(new TranslationAiConfiguration(
        provider: 'openai',
        registered: true,
        model: 'gpt-6-luna',
        apiKey: TranslationAiConfiguration::API_KEY_CONFIGURED,
        ready: true,
        connectTimeoutSeconds: 5.0,
        timeoutSeconds: 45.0,
        inputUsdPerMillionTokens: '0.100000',
        outputUsdPerMillionTokens: '0.500000',
    ));
});

it('is not ready when a translation would be refused before anything is sent', function (array $config, ?string $provider, bool $registered, string $apiKey) {
    config($config);

    $configuration = configuredTranslationAi();

    expect([$configuration->ready, $configuration->provider, $configuration->registered, $configuration->apiKey])->toBe([false, $provider, $registered, $apiKey]);
})->with([
    'no API key' => [['translation.providers.openai.api_key' => null], 'openai', true, TranslationAiConfiguration::API_KEY_MISSING],
    'a blank API key' => [['translation.providers.openai.api_key' => '  '], 'openai', true, TranslationAiConfiguration::API_KEY_MISSING],
    'no model' => [['translation.providers.openai.model' => ''], 'openai', true, TranslationAiConfiguration::API_KEY_CONFIGURED],
    'no provider' => [['translation.default' => ''], null, false, TranslationAiConfiguration::API_KEY_NOT_REQUIRED],
    'an unregistered provider' => [['translation.default' => 'nowhere'], 'nowhere', false, TranslationAiConfiguration::API_KEY_NOT_REQUIRED],
    'one that may not receive public content' => [['translation.providers.openai.allowed_classifications' => []], 'openai', true, TranslationAiConfiguration::API_KEY_CONFIGURED],
]);

it('needs no key for a provider whose entry takes none', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating(), settings: ['model' => 'scripted-1']);

    $configuration = configuredTranslationAi();

    expect([$configuration->ready, $configuration->apiKey, $configuration->model, $configuration->inputUsdPerMillionTokens])
        ->toBe([true, TranslationAiConfiguration::API_KEY_NOT_REQUIRED, 'scripted-1', null]);
});

it('gives the configured model no rates it does not have', function () {
    config(['translation.providers.openai.model' => 'gpt-7']);

    expect([configuredTranslationAi()->inputUsdPerMillionTokens, configuredTranslationAi()->outputUsdPerMillionTokens])->toBe([null, null]);
});
