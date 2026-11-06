<?php

namespace App\Support\Observability;

use Throwable;

final class ExceptionContextBuilder
{
    public function __construct(
        private readonly LogContext $logContext,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /** @return array<string, mixed> */
    public function build(Throwable $exception): array
    {
        $context = array_merge($this->logContext->base(), [
            'exception_class' => get_class($exception),
        ]);

        return $this->redactor->redact($context);
    }
}
