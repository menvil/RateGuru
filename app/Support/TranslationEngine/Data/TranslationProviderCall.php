<?php

namespace App\Support\TranslationEngine\Data;

use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Enums\TranslationStatus;

/**
 * One physical request to a provider, as it can safely be described: who
 * answered, how long it took, what it cost in tokens when the provider says,
 * and which items it carried.
 *
 * Returned with every result and stored nowhere. It is the raw material for
 * observability and cost accounting, not either of them.
 */
final readonly class TranslationProviderCall
{
    /**
     * @param  list<string>  $itemIds  the items this call carried, in the order they were sent
     * @param  string|null  $externalRequestId  the provider's own id for the request, to quote to its support
     */
    private function __construct(
        public string $provider,
        public string $model,
        public array $itemIds,
        public int $latencyMs,
        public TranslationStatus $status,
        public ?TranslationErrorCode $errorCode,
        public ?string $externalRequestId,
        public ?int $httpStatus,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?int $totalTokens,
    ) {}

    /** @param  list<string>  $itemIds */
    public static function succeeded(
        string $provider,
        string $model,
        array $itemIds,
        int $latencyMs,
        ?string $externalRequestId = null,
        ?int $httpStatus = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null,
    ): self {
        return new self(
            $provider, $model, $itemIds, $latencyMs, TranslationStatus::Success, null,
            $externalRequestId, $httpStatus, $inputTokens, $outputTokens, $totalTokens,
        );
    }

    /** @param  list<string>  $itemIds */
    public static function failed(
        string $provider,
        string $model,
        array $itemIds,
        int $latencyMs,
        TranslationErrorCode $errorCode,
        ?string $externalRequestId = null,
        ?int $httpStatus = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $totalTokens = null,
    ): self {
        return new self(
            $provider, $model, $itemIds, $latencyMs, TranslationStatus::Failed, $errorCode,
            $externalRequestId, $httpStatus, $inputTokens, $outputTokens, $totalTokens,
        );
    }

    /** The same call, judged failed after the fact — the provider answered, but not with anything usable. */
    public function failedWith(TranslationErrorCode $errorCode): self
    {
        return new self(
            $this->provider, $this->model, $this->itemIds, $this->latencyMs, TranslationStatus::Failed, $errorCode,
            $this->externalRequestId, $this->httpStatus, $this->inputTokens, $this->outputTokens, $this->totalTokens,
        );
    }

    public function isSuccessful(): bool
    {
        return $this->status === TranslationStatus::Success;
    }
}
