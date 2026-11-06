<?php

namespace App\Support\Translations\Telemetry;

use App\Models\TranslationAiProviderCall;
use App\Support\Observability\DomainLogger;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationBatchResult;
use App\Support\TranslationEngine\Data\TranslationItemResult;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use Carbon\CarbonImmutable;
use Throwable;
use WeakMap;

/**
 * Records the provider calls behind a result the translation engine returned:
 * one translation_ai_provider_calls row per TranslationProviderCall — one per
 * physical request, however many items it carried and however many requests
 * the engine cut the batch into.
 *
 * The engine stores nothing. Its consumers call this once translate() has
 * returned, naming themselves and the installed language:
 *
 *     $result = $this->translations->translate($request);
 *     $this->telemetry->record($request, $result, TranslationAiConsumer::InteractiveAi, $locale);
 *
 * Only what reached a provider is recorded. A batch the engine refused before
 * sending anything — no API key, a batch over its limits — has no calls and
 * records nothing; an item set apart as too large to send belongs to no call
 * and is counted nowhere, and neither is a request a provider's own size guard
 * refused before sending it. Item counts are the engine's own per-item results:
 * a call the provider answered can still carry items that failed — a
 * translation over its length limit — and those are failed items of a
 * successful call, not a failed call.
 *
 * A row holds metadata the call already carries — never a text, a prompt, a
 * glossary, an item id or anything a provider wrote. The provider's model
 * name and request id are kept only when they look like identifiers.
 *
 * Best effort, and never in a translation's way. A write that fails is logged
 * as translation.telemetry_write_failed — the exception's class, nothing it
 * said — and dropped: the translation is returned or stored exactly as it
 * would have been, and nothing is retried or sent again. The write runs in a
 * transaction of its own, a savepoint inside a caller's, so a failed insert
 * never aborts the caller's transaction.
 *
 * Each call is recorded once. Recording the same result again, or another
 * holding a call already recorded, writes nothing more — whether or not the
 * first write succeeded. It is a singleton, so that holds across a request or
 * a queue worker; the calls it remembers are held weakly and forgotten along
 * with their result.
 */
final class TranslationAiCallRecorder
{
    /** What a provider's model name or request id must look like to be kept. */
    private const IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9._:\/@+-]*$/D';

    private const MAX_UNSIGNED_INT = 4_294_967_295;

    /** @var WeakMap<TranslationProviderCall, bool> */
    private WeakMap $recorded;

    public function __construct(
        private readonly TranslationAiPricing $pricing,
        private readonly DomainLogger $logger,
    ) {
        $this->recorded = new WeakMap;
    }

    /**
     * @param  string  $targetLocale  the installed language the consumer translated into
     */
    public function record(
        TranslationBatchRequest $request,
        TranslationBatchResult $result,
        TranslationAiConsumer $consumer,
        string $targetLocale,
    ): void {
        $completedAt = $this->completionTimes($result->calls);
        $calls = [];

        foreach ($result->calls as $index => $call) {
            if (! isset($this->recorded[$call]) && $this->wasSent($call)) {
                // Marked before the write: a write that fails is not attempted again.
                $this->recorded[$call] = true;
                $calls[$index] = $call;
            }
        }

        if ($calls === []) {
            return;
        }

        try {
            $outcomes = [];

            foreach ($result->items as $item) {
                $outcomes[$item->id] = $item;
            }

            $rows = [];

            foreach ($calls as $index => $call) {
                $rows[] = $this->row($call, $completedAt[$index], $outcomes, $request, $consumer, $targetLocale);
            }

            (new TranslationAiProviderCall)->getConnection()->transaction(function () use ($rows): void {
                foreach ($rows as $row) {
                    TranslationAiProviderCall::query()->create($row);
                }
            });
        } catch (Throwable $exception) {
            $this->reportFailure($consumer, count($calls), $exception);
        }
    }

    /**
     * When each call completed, in UTC. The engine sends its requests one
     * after another and returns as the last one completes, so each call ended
     * the latencies of the calls after it before now.
     *
     * @param  list<TranslationProviderCall>  $calls
     * @return array<int, CarbonImmutable>
     */
    private function completionTimes(array $calls): array
    {
        $times = [];
        $time = CarbonImmutable::now('UTC');

        for ($index = count($calls) - 1; $index >= 0; $index--) {
            $times[$index] = $time;
            $time = $time->subMilliseconds(max(0, $calls[$index]->latencyMs));
        }

        return $times;
    }

    /**
     * @param  array<string, TranslationItemResult>  $outcomes
     * @return array<string, mixed>
     */
    private function row(
        TranslationProviderCall $call,
        CarbonImmutable $completedAt,
        array $outcomes,
        TranslationBatchRequest $request,
        TranslationAiConsumer $consumer,
        string $targetLocale,
    ): array {
        $succeeded = 0;
        $failures = [];

        foreach ($call->itemIds as $id) {
            $outcome = $outcomes[$id] ?? null;

            if ($outcome !== null && $outcome->isSuccessful()) {
                $succeeded++;

                continue;
            }

            $code = ($outcome->errorCode ?? TranslationErrorCode::InvalidProviderResponse)->value;
            $failures[$code] = ($failures[$code] ?? 0) + 1;
        }

        ksort($failures);

        $provider = $this->identifier($call->provider, 64) ?? 'unknown';
        $model = $this->identifier($call->model, 128) ?? 'unknown';
        $inputTokens = $this->tokens($call->inputTokens);
        $outputTokens = $this->tokens($call->outputTokens);
        $estimate = $this->pricing->estimate($provider, $model, $inputTokens, $outputTokens);

        return [
            'completed_at' => $completedAt,
            'provider' => $provider,
            'model' => $model,
            'consumer' => $consumer->value,
            'target_locale' => $this->identifier($targetLocale, 35) ?? 'unknown',
            'provider_target_locale' => $this->identifier($request->targetLocale, 35) ?? 'unknown',
            'status' => $call->status,
            'error_code' => $call->errorCode?->value,
            'http_status' => $call->httpStatus !== null && $call->httpStatus >= 100 && $call->httpStatus <= 599 ? $call->httpStatus : null,
            'external_request_id' => $this->identifier($call->externalRequestId, 255),
            'latency_ms' => min(max(0, $call->latencyMs), self::MAX_UNSIGNED_INT),
            'item_count' => count($call->itemIds),
            'succeeded_item_count' => $succeeded,
            'failed_item_count' => array_sum($failures),
            'item_failures' => $failures === [] ? null : $failures,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'total_tokens' => $this->tokens($call->totalTokens),
            'estimated_cost_usd' => $estimate->estimatedCostUsd,
            'input_usd_per_million_tokens' => $estimate->inputUsdPerMillionTokens,
            'output_usd_per_million_tokens' => $estimate->outputUsdPerMillionTokens,
        ];
    }

    /**
     * A request too large with no HTTP status never left the application: a
     * provider's own guard refused it. One the provider refused answered 413.
     */
    private function wasSent(TranslationProviderCall $call): bool
    {
        return ! ($call->errorCode === TranslationErrorCode::RequestTooLarge && $call->httpStatus === null);
    }

    private function identifier(?string $value, int $maxLength): ?string
    {
        return $value !== null && strlen($value) <= $maxLength && preg_match(self::IDENTIFIER, $value) === 1 ? $value : null;
    }

    private function tokens(?int $tokens): ?int
    {
        return $tokens !== null && $tokens >= 0 ? $tokens : null;
    }

    private function reportFailure(TranslationAiConsumer $consumer, int $callCount, Throwable $exception): void
    {
        try {
            $this->logger->warning('translation.telemetry_write_failed', [
                'consumer' => $consumer->value,
                'call_count' => $callCount,
                'exception_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // A log that cannot be written is not worth a translation either.
        }
    }
}
