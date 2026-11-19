<?php

namespace App\Services\Traffic\Connectors;

/**
 * The secrets of one connection while a run talks to its network — the key,
 * and for ExoClick the session it signed in for — and whether the run
 * changed them, so they are saved back encrypted.
 */
final class ConnectorSession
{
    private bool $changed = false;

    /** @param  array<string, mixed>  $credentials */
    public function __construct(private array $credentials) {}

    public function get(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function put(string $key, ?string $value): void
    {
        $this->credentials[$key] = $value;
        $this->changed = true;
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        return $this->credentials;
    }

    public function changed(): bool
    {
        return $this->changed;
    }
}
