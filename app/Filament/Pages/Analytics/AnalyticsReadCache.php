<?php

namespace App\Filament\Pages\Analytics;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The figures of a Growth → Analytics tab that is slow to read, kept for a
 * few minutes once read (`analytics.read_cache_minutes`): Analytics counts
 * straight from the visits with no totals kept aside, so a tab that takes
 * seconds on a busy site is read again only once what was kept is older
 * than that, or when Refresh asks. Kept for one administrator — what one
 * may see is never handed to another — and by the tab and everything on
 * the page its figures depend on, with the moment they were read, which the
 * page says. What the filters offer, read from every visit of the period
 * too, is kept the same way (AnalyticsView). A segment or tracking link the
 * page changed drops everything kept, for every administrator (forgetAll).
 */
final class AnalyticsReadCache
{
    private const string PREFIX = 'analytics-figures';

    /** What every key carries, changed to drop everything kept at once. */
    private const string VERSION = self::PREFIX.':version';

    /**
     * What was read — a tab's figures, or what a filter offers — as kept,
     * or, none kept or asked for afresh, as read now, and kept.
     *
     * @param  string  $name  the tab, or what else is kept
     * @param  array<string, mixed>  $state  everything on the page what is read depends on
     * @param  Closure(): array<array-key, mixed>  $read
     * @return array{data: array<array-key, mixed>, readAt: CarbonImmutable}
     */
    public function remember(int $userId, string $name, array $state, bool $fresh, Closure $read): array
    {
        $key = self::key($userId, $name, $state);
        $kept = $fresh ? null : Cache::get($key);

        if (is_array($kept) && is_array($kept['data'] ?? null) && is_string($kept['at'] ?? null)) {
            return ['data' => $kept['data'], 'readAt' => CarbonImmutable::parse($kept['at'])->utc()];
        }

        $data = $read();
        $at = CarbonImmutable::now('UTC')->startOfSecond();
        Cache::put($key, ['at' => $at->toIso8601String(), 'data' => $data], $at->addMinutes((int) config('analytics.read_cache_minutes')));

        return ['data' => $data, 'readAt' => $at];
    }

    /**
     * A segment or tracking link changed what the tabs show: everything kept,
     * for every administrator, on any tab, filter or link details, is read
     * afresh from now on. A new token rather than an increment, which a
     * missing key doesn't take alike on every store.
     */
    public function forgetAll(): void
    {
        Cache::forever(self::VERSION, (string) Str::ulid());
    }

    /** @param  array<string, mixed>  $state */
    private static function key(int $userId, string $name, array $state): string
    {
        ksort($state);
        $version = Cache::get(self::VERSION);

        return self::PREFIX.':'.(is_string($version) ? $version : '0').':'.$userId.':'.$name.':'.hash('xxh128', serialize($state));
    }
}
