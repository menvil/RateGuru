<?php

namespace App\Support\TranslationEngine\Providers;

use App\Support\TranslationEngine\Contracts\TranslationProvider;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Data\TranslationProviderResponse;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\TranslationEngine\TranslationPromptBuilder;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * Translates a batch with OpenAI's Responses API: one ordinary, synchronous
 * request per batch, carrying every item of it.
 *
 * Not the asynchronous Batch API, and not a conversation. Each request is
 * stateless — `store` is false, and there is no conversation, previous
 * response, tool, file or assistant — so the provider keeps nothing of ours
 * between calls.
 *
 * The answer is required as strict Structured Output against a JSON schema,
 * read from the response's `output_text` content and decoded as JSON. Nothing
 * is recovered from prose or Markdown fences: an answer that is not the
 * structured output is an invalid response, whatever it looks like.
 *
 * No retries, and no redirects: a retry can silently double a paid call, and
 * whether to retry is a policy decided above the engine; a redirect from the
 * API would carry the bearer token somewhere nobody configured.
 *
 * The API key is read from configuration into this object and never passed
 * anywhere as an argument but the Authorization header. Failures are reported
 * as an error code and safe call metadata; the response body and the HTTP
 * client's exception — whose trace holds the request headers — are dropped.
 *
 * The model is configuration (translation.providers.{name}.model); nothing
 * here or anywhere in the engine names one.
 */
final class OpenAiTranslationProvider implements TranslationProvider
{
    private const SCHEMA_NAME = 'translation_batch';

    private string $apiKey;

    private string $model;

    private string $endpoint;

    private float $connectTimeout;

    private float $timeout;

    private TranslationProviderLimits $limits;

    /**
     * @throws TranslationConfigurationException when a setting it needs is missing — before any request
     */
    public function __construct(
        private readonly string $name,
        private readonly TranslationPromptBuilder $prompts,
    ) {
        $registry = config('translation.providers');
        $settings = is_array($registry) && is_array($registry[$name] ?? null) ? $registry[$name] : [];

        // Each setting is handed to its check on its own, never the whole
        // array: a refusal's stack trace then holds the one value that was
        // wrong, not the API key beside it.
        $this->apiKey = $this->requiredString($settings['api_key'] ?? null, 'api_key');
        $this->model = $this->requiredString($settings['model'] ?? null, 'model');
        $this->endpoint = $this->endpointFrom($settings['base_url'] ?? null);
        $this->connectTimeout = $this->positiveNumber($settings['connect_timeout'] ?? null, 'connect_timeout');
        $this->timeout = $this->positiveNumber($settings['timeout'] ?? null, 'timeout');
        $this->limits = new TranslationProviderLimits(
            $this->positiveInteger($settings['max_items'] ?? null, 'max_items'),
            $this->positiveInteger($settings['max_payload_chars'] ?? null, 'max_payload_chars'),
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function limits(): TranslationProviderLimits
    {
        return $this->limits;
    }

    public function translateBatch(TranslationBatchRequest $request): TranslationProviderResponse
    {
        $itemIds = $request->itemIds();

        // TranslationService only sends requests within limits(); this is the
        // provider's own guard should anything else ever call it.
        if (count($itemIds) > $this->limits->maxItems
            || $this->prompts->providerVisibleLength($request) > $this->limits->maxProviderVisibleChars) {
            throw $this->failure(TranslationErrorCode::RequestTooLarge, $itemIds, 0);
        }

        $started = hrtime(true);

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->post($this->endpoint, $this->body($request));
        } catch (HttpClientException|TransferException $exception) {
            $code = $this->isTimeout($exception->getMessage())
                ? TranslationErrorCode::ProviderTimeout
                : TranslationErrorCode::ProviderUnavailable;

            throw $this->failure($code, $itemIds, $this->elapsedMs($started));
        }

        $latencyMs = $this->elapsedMs($started);
        $status = $response->status();
        $headerRequestId = $this->headerRequestId($response);

        if (! $response->successful()) {
            throw $this->failure($this->codeForStatus($status), $itemIds, $latencyMs, $headerRequestId, $status);
        }

        try {
            $document = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->failure(TranslationErrorCode::InvalidProviderResponse, $itemIds, $latencyMs, $headerRequestId, $status);
        }

        if (! is_array($document)) {
            throw $this->failure(TranslationErrorCode::InvalidProviderResponse, $itemIds, $latencyMs, $headerRequestId, $status);
        }

        // Whatever else is wrong with the response, its id and usage are kept:
        // the tokens were spent either way.
        $requestId = $this->stringOrNull($document['id'] ?? null) ?? $headerRequestId;
        $model = $this->stringOrNull($document['model'] ?? null) ?? $this->model;
        $usage = is_array($document['usage'] ?? null) ? $document['usage'] : [];

        $translations = $this->translationsFrom($document);

        if ($translations instanceof TranslationErrorCode) {
            throw new TranslationProviderException($translations, TranslationProviderCall::failed(
                $this->name, $model, $itemIds, $latencyMs, $translations, $requestId, $status,
                $this->tokens($usage, 'input_tokens'), $this->tokens($usage, 'output_tokens'), $this->tokens($usage, 'total_tokens'),
            ));
        }

        return new TranslationProviderResponse($translations, TranslationProviderCall::succeeded(
            $this->name, $model, $itemIds, $latencyMs, $requestId, $status,
            $this->tokens($usage, 'input_tokens'), $this->tokens($usage, 'output_tokens'), $this->tokens($usage, 'total_tokens'),
        ));
    }

    /**
     * The JSON schema the answer must follow, in strict mode: an object with
     * exactly a list of {id, text} objects, nothing more.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(): array
    {
        return [
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
        ];
    }

    /** @return array<string, mixed> */
    private function body(TranslationBatchRequest $request): array
    {
        return [
            'model' => $this->model,
            'store' => false,
            'instructions' => $this->prompts->instructions(),
            'input' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $this->prompts->input($request)],
                    ],
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => self::SCHEMA_NAME,
                    'strict' => true,
                    'schema' => self::responseSchema(),
                ],
            ],
        ];
    }

    /**
     * The structured output of a completed response, checked for shape only.
     *
     * A response may hold several output items — reasoning before the
     * message, for instance — and a message several content parts, so the
     * one `output_text` is looked for rather than assumed to come first. No
     * text, more than one, or a refusal is not a usable answer.
     *
     * @param  array<mixed>  $document
     * @return list<array{id: string, text: string}>|TranslationErrorCode
     */
    private function translationsFrom(array $document): array|TranslationErrorCode
    {
        if (($document['status'] ?? 'completed') !== 'completed') {
            return TranslationErrorCode::InvalidProviderResponse;
        }

        $output = $document['output'] ?? null;

        if (! is_array($output) || ! array_is_list($output)) {
            return TranslationErrorCode::InvalidProviderResponse;
        }

        $texts = [];
        $refused = false;

        foreach ($output as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'message' || ! is_array($entry['content'] ?? null)) {
                continue;
            }

            foreach ($entry['content'] as $part) {
                $type = is_array($part) ? ($part['type'] ?? null) : null;

                if ($type === 'refusal') {
                    $refused = true;
                } elseif ($type === 'output_text') {
                    $texts[] = $part['text'] ?? null;
                }
            }
        }

        if ($refused) {
            return TranslationErrorCode::ProviderRefused;
        }

        if (count($texts) !== 1 || ! is_string($texts[0])) {
            return TranslationErrorCode::InvalidProviderResponse;
        }

        try {
            $structured = json_decode($texts[0], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return TranslationErrorCode::InvalidProviderResponse;
        }

        if (! is_array($structured) || array_keys($structured) !== ['translations']
            || ! is_array($structured['translations']) || ! array_is_list($structured['translations'])) {
            return TranslationErrorCode::InvalidProviderResponse;
        }

        $translations = [];

        foreach ($structured['translations'] as $translation) {
            if (! is_array($translation) || count($translation) !== 2
                || ! is_string($translation['id'] ?? null) || ! is_string($translation['text'] ?? null)) {
                return TranslationErrorCode::InvalidProviderResponse;
            }

            $translations[] = ['id' => $translation['id'], 'text' => $translation['text']];
        }

        return $translations;
    }

    private function codeForStatus(int $status): TranslationErrorCode
    {
        return match (true) {
            $status === 401, $status === 403 => TranslationErrorCode::AuthenticationFailed,
            $status === 429 => TranslationErrorCode::RateLimited,
            $status === 408, $status === 504 => TranslationErrorCode::ProviderTimeout,
            $status === 413 => TranslationErrorCode::RequestTooLarge,
            $status >= 400 && $status < 500 => TranslationErrorCode::ProviderRejectedRequest,
            default => TranslationErrorCode::ProviderUnavailable,
        };
    }

    /** cURL reports both a connect and a transfer timeout as error 28. */
    private function isTimeout(string $message): bool
    {
        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
    }

    /** @param  list<string>  $itemIds */
    private function failure(
        TranslationErrorCode $code,
        array $itemIds,
        int $latencyMs,
        ?string $requestId = null,
        ?int $httpStatus = null,
    ): TranslationProviderException {
        return new TranslationProviderException($code, TranslationProviderCall::failed(
            $this->name, $this->model, $itemIds, $latencyMs, $code, $requestId, $httpStatus,
        ));
    }

    private function headerRequestId(Response $response): ?string
    {
        return $this->stringOrNull($response->header('x-request-id'));
    }

    private function elapsedMs(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param  array<mixed>  $usage */
    private function tokens(array $usage, string $key): ?int
    {
        $value = $usage[$key] ?? null;

        return is_int($value) && $value >= 0 ? $value : null;
    }

    private function requiredString(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw TranslationConfigurationException::missingSetting($this->name, $key);
        }

        return trim($value);
    }

    private function endpointFrom(mixed $value): string
    {
        $baseUrl = $this->requiredString($value, 'base_url');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            throw TranslationConfigurationException::missingSetting($this->name, 'base_url');
        }

        return rtrim($baseUrl, '/').'/responses';
    }

    private function positiveNumber(mixed $value, string $key): float
    {
        if ((! is_int($value) && ! is_float($value)) || $value <= 0) {
            throw TranslationConfigurationException::missingSetting($this->name, $key);
        }

        return (float) $value;
    }

    private function positiveInteger(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw TranslationConfigurationException::missingSetting($this->name, $key);
        }

        return $value;
    }
}
