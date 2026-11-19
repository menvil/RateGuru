<?php

namespace App\Services\Traffic;

/**
 * The network presets and connectors of config/traffic_presets.php, as the
 * sources screens and actions read them. A key the file no longer has is
 * "no preset": the source keeps what it copied.
 */
final class TrafficPresetCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $presets = config('traffic_presets.presets');

        return is_array($presets) ? $presets : [];
    }

    /** @return array<string, mixed>|null */
    public function find(?string $key): ?array
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** @return array<string, mixed>|null */
    public function connector(?string $key): ?array
    {
        $connectors = config('traffic_presets.connectors');

        return $key === null || ! is_array($connectors) ? null : ($connectors[$key] ?? null);
    }
}
