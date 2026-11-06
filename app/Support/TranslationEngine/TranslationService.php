<?php

namespace App\Support\TranslationEngine;

use App\Support\Observability\DomainLogger;
use App\Support\TranslationEngine\Contracts\TranslationProvider;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationBatchResult;
use App\Support\TranslationEngine\Data\TranslationItem;
use App\Support\TranslationEngine\Data\TranslationItemResult;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\InvalidTranslationRequestException;
use App\Support\TranslationEngine\Exceptions\TranslationConfigurationException;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;

/**
 * RateGuru's translation engine: the one thing a consumer calls to have text
 * machine-translated.
 *
 *     consumer → TranslationBatchRequest → TranslationService
 *              → TranslationProviderRouter → TranslationProvider
 *
 * A consumer hands over a batch and gets a result for every item back. It
 * never chooses a provider, writes a prompt, or knows how the batch was cut
 * into provider requests.
 *
 * What it does, in order:
 *
 *  1. Holds the batch to the logical limits (translation.max_batch_items,
 *     translation.max_batch_chars) — a guard against a runaway consumer;
 *     bigger work is several batches, which is an orchestrator's job.
 *  2. Asks the router for the provider, which refuses before anything is sent
 *     when none may take this batch.
 *  3. Cuts the batch to the provider's limits; an item too large for any
 *     request fails as request_too_large and is never cut.
 *  4. Sends each request in turn. A request that fails fails its own items;
 *     the requests before and after it keep their translations.
 *  5. Trusts nothing that comes back. Translations are matched by id, never by
 *     position; an unknown or repeated id voids the whole response; a missing
 *     one fails its item. Each text is normalized as Translation Center's save
 *     normalizes it and held to its item's constraints.
 *
 * It translates text and nothing more. It stores nothing — no database, no
 * cache, no queue — retries nothing, and falls back to no other provider:
 * persistence, retry and fallback are policies of the consumers and
 * orchestration built on top of it.
 */
final class TranslationService
{
    public function __construct(
        private readonly TranslationProviderRouter $router,
        private readonly TranslationBatchChunker $chunker,
        private readonly TranslationPromptBuilder $prompts,
        private readonly DomainLogger $logger,
    ) {}

    /**
     * @throws InvalidTranslationRequestException when the batch exceeds the logical limits
     * @throws TranslationConfigurationException when no provider may take the batch
     */
    public function translate(TranslationBatchRequest $request): TranslationBatchResult
    {
        $this->assertWithinLogicalLimits($request);

        $provider = $this->router->providerFor($request);
        $plan = $this->chunker->chunk($request, $provider->limits());

        /** @var array<string, TranslationItemResult> $results */
        $results = [];
        $calls = [];

        foreach ($plan->oversized as $item) {
            $results[$item->id] = TranslationItemResult::failed($item->id, TranslationErrorCode::RequestTooLarge);
        }

        foreach ($plan->chunks as $chunk) {
            [$chunkResults, $call] = $this->translateChunk($provider, $chunk);

            $results += $chunkResults;
            $calls[] = $call;
        }

        return new TranslationBatchResult(
            array_map(static fn (TranslationItem $item): TranslationItemResult => $results[$item->id], $request->items),
            $calls,
        );
    }

    private function assertWithinLogicalLimits(TranslationBatchRequest $request): void
    {
        $maxItems = $this->positiveSetting('translation.max_batch_items');
        $maxChars = $this->positiveSetting('translation.max_batch_chars');

        if (count($request->items) > $maxItems) {
            throw InvalidTranslationRequestException::forBatch(
                'holds '.count($request->items)." items; one batch holds at most {$maxItems} — split the work into several batches",
            );
        }

        $length = $this->prompts->providerVisibleLength($request);

        if ($length > $maxChars) {
            throw InvalidTranslationRequestException::forBatch(
                "carries {$length} provider-visible characters; one batch carries at most {$maxChars} — split the work into several batches",
            );
        }
    }

    private function positiveSetting(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value < 1) {
            throw TranslationConfigurationException::invalidLimit($key);
        }

        return $value;
    }

    /**
     * @return array{0: array<string, TranslationItemResult>, 1: TranslationProviderCall}
     */
    private function translateChunk(TranslationProvider $provider, TranslationBatchRequest $chunk): array
    {
        try {
            $response = $provider->translateBatch($chunk);
        } catch (TranslationProviderException $exception) {
            $this->logFailedCall($exception->call);

            return [$this->failAll($chunk, $exception->errorCode), $exception->call];
        }

        $expected = array_flip($chunk->itemIds());
        $returned = [];

        foreach ($response->translations as $translation) {
            // A translation for an item this request did not carry, or a
            // second one for the same item, means the response cannot be
            // matched to the request with confidence — none of it is used.
            if (! isset($expected[$translation['id']]) || isset($returned[$translation['id']])) {
                $call = $response->call->failedWith(TranslationErrorCode::InvalidProviderResponse);
                $this->logFailedCall($call);

                return [$this->failAll($chunk, TranslationErrorCode::InvalidProviderResponse), $call];
            }

            $returned[$translation['id']] = $translation['text'];
        }

        $results = [];

        foreach ($chunk->items as $item) {
            $results[$item->id] = isset($returned[$item->id])
                ? $this->checked($item, $returned[$item->id])
                : TranslationItemResult::failed(
                    $item->id,
                    TranslationErrorCode::InvalidProviderResponse,
                    'The translation provider returned no translation for this item.',
                );
        }

        return [$results, $response->call];
    }

    /**
     * Normalizes a translation the way Translation Center's save does
     * (UpdateProjectTranslationAction: line endings to LF, outer whitespace
     * trimmed, nothing inside touched), then holds it to its item's
     * constraints — characters counted as code points, as that save counts
     * them. A translation that breaks one is not a success.
     */
    private function checked(TranslationItem $item, string $text): TranslationItemResult
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return TranslationItemResult::failed($item->id, TranslationErrorCode::ConstraintViolation, 'The translation is blank.');
        }

        $violations = [];

        if (! $item->multiline && str_contains($text, "\n")) {
            $violations[] = 'The translation spans more than one line.';
        }

        if ($item->maxLength !== null && mb_strlen($text) > $item->maxLength) {
            $over = mb_strlen($text) - $item->maxLength;
            $violations[] = "The translation is {$over} characters over the limit of {$item->maxLength}.";
        }

        $lost = array_values(array_filter(
            $item->placeholders,
            static fn (string $placeholder): bool => ! str_contains($text, $placeholder),
        ));

        if ($lost !== []) {
            $violations[] = 'The translation lost '.implode(', ', $lost).'.';
        }

        if ($violations !== []) {
            return TranslationItemResult::failed($item->id, TranslationErrorCode::ConstraintViolation, implode(' ', $violations));
        }

        return TranslationItemResult::success($item->id, $text);
    }

    /** @return array<string, TranslationItemResult> */
    private function failAll(TranslationBatchRequest $chunk, TranslationErrorCode $errorCode): array
    {
        $results = [];

        foreach ($chunk->items as $item) {
            $results[$item->id] = TranslationItemResult::failed($item->id, $errorCode);
        }

        return $results;
    }

    /**
     * What may be said about a failed call: who, how long, what status, the
     * provider's request id. Never a source text, a translation, a request
     * body or a credential.
     */
    private function logFailedCall(TranslationProviderCall $call): void
    {
        $this->logger->warning('translation.provider_call_failed', [
            'provider' => $call->provider,
            'model' => $call->model,
            'error_code' => $call->errorCode?->value,
            'http_status' => $call->httpStatus,
            'external_request_id' => $call->externalRequestId,
            'item_count' => count($call->itemIds),
            'latency_ms' => $call->latencyMs,
        ]);
    }
}
