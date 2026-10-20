<?php

namespace App\Support\StudioAi;

/**
 * What can be known of one request for a discussion without its content: the
 * model that answered, its request id, the HTTP status, how long it took and
 * the tokens it reported — null where it reported none.
 */
final readonly class StudioDiscussionGenerationCall
{
    public function __construct(
        public string $model,
        public int $latencyMs,
        public ?string $requestId = null,
        public ?int $httpStatus = null,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?int $totalTokens = null,
    ) {}

    /**
     * The call as a log line may carry it: never a comment, a character, the
     * instructions or the key.
     *
     * @return array{model: string, latency_ms: int, provider_request_id: string|null, http_status: int|null, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null}
     */
    public function logContext(): array
    {
        return [
            'model' => $this->model,
            'latency_ms' => $this->latencyMs,
            'provider_request_id' => $this->requestId,
            'http_status' => $this->httpStatus,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }

    /**
     * As a generation's row keeps it.
     *
     * @return array{model: string, request_id: string|null, input_tokens: int|null, output_tokens: int|null, total_tokens: int|null, latency_ms: int}
     */
    public function attributes(): array
    {
        return [
            'model' => mb_substr($this->model, 0, 128),
            'request_id' => $this->requestId !== null ? mb_substr($this->requestId, 0, 255) : null,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
