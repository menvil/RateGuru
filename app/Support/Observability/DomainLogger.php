<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;

final class DomainLogger
{
    public function __construct(
        private readonly LogContext $logContext,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function info(string $event, array $context = []): void
    {
        Log::info($event, $this->build($context));
    }

    public function warning(string $event, array $context = []): void
    {
        Log::warning($event, $this->build($context));
    }

    public function error(string $event, array $context = []): void
    {
        Log::error($event, $this->build($context));
    }

    public function security(string $event, array $context = []): void
    {
        Log::warning($event, $this->build(array_merge($context, ['event_type' => 'security'])));
    }

    private function build(array $context): array
    {
        $merged = array_merge($this->logContext->base(), $context);

        return $this->redactor->redact($merged);
    }
}
