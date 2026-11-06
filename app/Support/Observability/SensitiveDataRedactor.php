<?php

namespace App\Support\Observability;

final class SensitiveDataRedactor
{
    private const REDACTED = '[redacted]';

    /** @param array<string, mixed> $data */
    public function redact(array $data): array
    {
        $keys = array_map('strtolower', config('observability.redaction.keys', []));
        $result = [];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $keys, true)) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $result[$key] = $this->redact($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
