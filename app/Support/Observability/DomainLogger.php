<?php

namespace App\Support\Observability;

use App\Exceptions\Observability\InvalidLogEventNameException;
use Illuminate\Support\Facades\Log;

final class DomainLogger
{
    public function __construct(
        private readonly LogContext $logContext,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    public function info(string $event, array $context = []): void
    {
        $this->validate($event);
        Log::info($event, $this->build($context));
    }

    public function warning(string $event, array $context = []): void
    {
        $this->validate($event);
        Log::warning($event, $this->build($context));
    }

    public function error(string $event, array $context = []): void
    {
        $this->validate($event);
        Log::error($event, $this->build($context));
    }

    public function security(string $event, array $context = []): void
    {
        $this->validate($event);
        Log::warning($event, $this->build(array_merge($context, ['event_type' => 'security'])));
    }

    private function validate(string $event): void
    {
        if (! LogEventName::isValid($event)) {
            throw InvalidLogEventNameException::for($event);
        }
    }

    private function build(array $context): array
    {
        $merged = array_merge($context, $this->logContext->base());

        return $this->redactor->redact($merged);
    }
}
