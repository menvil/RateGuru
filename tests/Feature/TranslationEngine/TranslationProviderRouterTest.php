<?php

use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Enums\TranslationDataClassification;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\TranslationEngine\Providers\OpenAiTranslationProvider;
use App\Support\TranslationEngine\TranslationProviderRouter;
use App\Support\TranslationEngine\TranslationService;
use Illuminate\Support\Facades\Http;

/**
 * Which provider a batch goes to, and every way the choice is refused — always
 * before anything leaves the application.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
});

function translationRouterRefusal(Closure $attempt): TranslationConfigurationException
{
    try {
        $attempt();
    } catch (TranslationConfigurationException $exception) {
        return $exception;
    }

    throw new RuntimeException('the router should have refused');
}

// =============================================================================
// The configured default
// =============================================================================

it('routes to OpenAI by default', function () {
    configureOpenAiTranslation();

    expect(config('translation.default'))->toBe('openai')
        ->and(app(TranslationProviderRouter::class)->providerFor(translationBatch()))
        ->toBeInstanceOf(OpenAiTranslationProvider::class);
});

it('names the provider by its registry entry, with OpenAI\'s limits', function () {
    configureOpenAiTranslation();

    $provider = app(TranslationProviderRouter::class)->providerFor(translationBatch());

    expect($provider->name())->toBe('openai')
        ->and($provider->limits()->maxItems)->toBe(50)
        ->and($provider->limits()->maxProviderVisibleChars)->toBe(60_000);
});

it('ships OpenAI\'s defaults: the model, the endpoint, the timeouts and the limits', function () {
    expect(config('translation.providers.openai'))->toMatchArray([
        'driver' => OpenAiTranslationProvider::class,
        'model' => 'gpt-6-luna',
        'base_url' => 'https://api.openai.com/v1',
        'connect_timeout' => 5,
        'timeout' => 45,
        'max_items' => 50,
        'max_payload_chars' => 60_000,
        'allowed_classifications' => ['public_content', 'public_user_generated'],
    ])->and(config('translation.max_batch_items'))->toBe(500)
        ->and(config('translation.max_batch_chars'))->toBe(500_000);
});

// =============================================================================
// Booting without a key
// =============================================================================

it('boots, resolves the engine and caches its configuration without an API key', function () {
    config(['translation.providers.openai.api_key' => null]);

    expect(app(TranslationService::class))->toBeInstanceOf(TranslationService::class)
        ->and(app(TranslationProviderRouter::class))->toBeInstanceOf(TranslationProviderRouter::class);

    // config:cache writes var_export() of the configuration; the engine's
    // entry has to survive that round trip unchanged.
    $cached = eval('return '.var_export(config('translation'), true).';');

    expect($cached)->toBe(config('translation'));
});

it('refuses an actual translation without an API key, as not configured, before any request', function (?string $key) {
    configureOpenAiTranslation(['api_key' => $key]);

    $refusal = translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()));

    expect($refusal->errorCode)->toBe(TranslationErrorCode::NotConfigured)
        ->and($refusal->provider)->toBe('openai')
        ->and($refusal->getMessage())->toContain('[api_key]');

    Http::assertNothingSent();
})->with(['null' => null, 'empty' => '', 'blank' => '   ']);

it('refuses OpenAI without a model or a usable endpoint, naming the setting and never the key', function (string $setting, mixed $value) {
    configureOpenAiTranslation([$setting => $value]);

    $refusal = translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()));

    expect($refusal->errorCode)->toBe(TranslationErrorCode::NotConfigured)
        ->and($refusal->getMessage())->toContain("[{$setting}]")
        ->and((string) $refusal)->not->toContain(TRANSLATION_TEST_API_KEY);

    // Its own frames carry the one setting that was wrong, never the key
    // beside it — whatever zend.exception_ignore_args says.
    foreach ($refusal->getTrace() as $frame) {
        expect(translationValueContains($frame['args'] ?? [], TRANSLATION_TEST_API_KEY))->toBeFalse();
    }

    Http::assertNothingSent();
})->with([
    'no model' => ['model', ''],
    'no base URL' => ['base_url', ''],
    'a base URL that is not one' => ['base_url', 'api.openai.com/v1'],
    'a base URL that is not HTTP' => ['base_url', 'ftp://api.openai.com/v1'],
    'no timeout' => ['timeout', 0],
    'no item limit' => ['max_items', 0],
]);

// =============================================================================
// Refusing a provider that does not exist
// =============================================================================

it('refuses cleanly when the configured provider is not registered', function () {
    config(['translation.default' => 'deepl']);

    $refusal = translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()));

    expect($refusal->errorCode)->toBe(TranslationErrorCode::NotConfigured)
        ->and($refusal->getMessage())->toBe('Translation provider [deepl] is not registered in translation.providers.');

    Http::assertNothingSent();
});

it('refuses when no provider is configured at all', function (?string $default) {
    config(['translation.default' => $default]);

    expect(translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()))->errorCode)
        ->toBe(TranslationErrorCode::NotConfigured);
})->with(['null' => null, 'empty' => '']);

it('refuses a registry entry whose driver is not a translation provider', function (mixed $driver) {
    config([
        'translation.default' => 'broken',
        'translation.providers.broken' => ['driver' => $driver, 'allowed_classifications' => ['public_content']],
    ]);

    expect(translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()))->getMessage())
        ->toContain('does not name a driver that implements the translation provider contract');
})->with([
    'missing' => null,
    'not a class' => 'NoSuchProvider',
    'a class that is not a provider' => stdClass::class,
]);

// =============================================================================
// The data classification decides who may receive a batch
// =============================================================================

it('lets OpenAI receive public content and public user-generated content', function (TranslationDataClassification $classification) {
    configureOpenAiTranslation();

    expect(app(TranslationProviderRouter::class)->providerFor(translationBatch(classification: $classification)))
        ->toBeInstanceOf(OpenAiTranslationProvider::class);
})->with([
    TranslationDataClassification::PublicContent,
    TranslationDataClassification::PublicUserGenerated,
]);

it('never sends private content to OpenAI, refusing before any request', function () {
    configureOpenAiTranslation();

    $refusal = translationRouterRefusal(fn () => app(TranslationService::class)->translate(
        translationBatch(classification: TranslationDataClassification::PrivateContent),
    ));

    expect($refusal->errorCode)->toBe(TranslationErrorCode::ClassificationNotAllowed)
        ->and($refusal->getMessage())->toBe('Translation provider [openai] may not receive private_content data.');

    Http::assertNothingSent();
});

it('refuses private content to OpenAI before it even checks for a key', function () {
    configureOpenAiTranslation(['api_key' => null]);

    expect(translationRouterRefusal(fn () => app(TranslationService::class)->translate(
        translationBatch(classification: TranslationDataClassification::PrivateContent),
    ))->errorCode)->toBe(TranslationErrorCode::ClassificationNotAllowed);
});

it('lets a provider receive nothing whose entry lists no classifications', function (mixed $allowed) {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating(), settings: ['allowed_classifications' => $allowed]);

    expect(translationRouterRefusal(fn () => app(TranslationService::class)->translate(translationBatch()))->errorCode)
        ->toBe(TranslationErrorCode::ClassificationNotAllowed);
})->with([
    'an empty list' => [[]],
    'not a list' => ['public_content'],
    'missing' => null,
]);

// =============================================================================
// A provider is added by registering it
// =============================================================================

it('routes to a provider added to the registry, without any change to the router', function () {
    // A stand-in for a provider on our own hardware: registered under its
    // own name, accepting every classification, private content included.
    $local = useScriptedTranslationProvider(ScriptedTranslationProvider::translating(name: 'local'), name: 'local');

    $result = app(TranslationService::class)->translate(
        translationBatch(classification: TranslationDataClassification::PrivateContent),
    );

    expect(app(TranslationProviderRouter::class)->providerFor(translationBatch()))->toBe($local)
        ->and($local->received)->toHaveCount(1)
        ->and($result->successful())->toHaveCount(1)
        ->and($result->items[0]->text)->toBe('[de] Dogs');

    Http::assertNothingSent();
});

it('sends nothing to a second provider when the configured one fails', function () {
    // No fallback: the OpenAI entry is configured and would accept the batch,
    // but it is not the default, so a failing default leaves the items failed.
    configureOpenAiTranslation();

    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn ($request) => throw new TranslationProviderException(
            TranslationErrorCode::ProviderUnavailable,
            TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 1, TranslationErrorCode::ProviderUnavailable),
        ),
    ));

    $result = app(TranslationService::class)->translate(translationBatch());

    expect($result->items[0]->errorCode)->toBe(TranslationErrorCode::ProviderUnavailable)
        ->and($result->calls)->toHaveCount(1);

    Http::assertNothingSent();
});
