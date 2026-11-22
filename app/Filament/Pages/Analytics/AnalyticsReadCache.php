<?php

namespace App\Filament\Pages\Analytics;

use App\Models\User;
use App\Support\Analytics\AnalyticsPeriod;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The figures of a Growth → Analytics tab that is slow to read, kept for a
 * few minutes once read (`analytics.read_cache_minutes`): Analytics counts
 * straight from the visits with no totals kept aside, so a tab that takes
 * seconds on a busy site is read again only once what was kept is older
 * than that, or when Refresh asks. Kept for everyone who may open the page,
 * by the tab and everything on the page its figures depend on, with the
 * moment they were read, which the page says. What the filters offer, read
 * from every visit of the period too, is kept the same way (AnalyticsView).
 * A segment or tracking link the page changed drops everything kept
 * (forgetAll).
 *
 * Nothing kept depends on who reads it. The tabs, the shell and the queries
 * under them ask nobody's account or session: the period an administrator
 * last chose (ReportPeriodMemory) only decides which period the page opens
 * on, and that period is part of what is kept by. What a tab shows by who
 * is reading — a tracking link's details lead to its game, and Games' traffic
 * periods to their results in Experiments, only for someone who may manage
 * games — is kept by that permission as well
 * (GrowthAnalyticsPage::readState()); whatever else a tab shows only to
 * someone who may manage traffic sources, everyone who may open the page
 * may. Every address in the figures starts with the site's address the page
 * was asked at, so what is kept is kept by that too. What is kept holds
 * plain values only: a store gives back no object
 * (`cache.serializable_classes`), so one would come back as no class.
 *
 * Read once for all: someone asking for figures another request is reading
 * at that moment — a second administrator, or the figures being read ahead
 * (analytics:warm-figures) — waits for them, at most WAIT_SECONDS, and reads
 * them only when none were kept by then. Refresh never waits. Figures are as
 * of the moment their read began, and a read that ends after one begun later
 * — a Refresh while it read — leaves the later one's kept. Each view an
 * administrator opens or chooses is noted (opened()), so the figures of the
 * views most likely to be opened next are read ahead before anyone asks,
 * each as an administrator who opened it reads it (recentViews(), ahead()).
 */
final class AnalyticsReadCache
{
    private const string PREFIX = 'analytics-figures';

    /** What every key carries, changed to drop everything kept at once. */
    private const string VERSION = self::PREFIX.':version';

    /** The view each administrator last opened or chose, and when. */
    private const string VIEWS = self::PREFIX.':views';

    /** How long one asking for figures being read waits for them at most, in seconds, before reading them itself. */
    public const int WAIT_SECONDS = 30;

    /** How long a read keeps others waiting at most, in seconds, should it never finish: as long as a request may take. */
    private const int READING_SECONDS = 120;

    /** What was kept counts only while it is kept past this moment; none, anything kept counts. */
    private ?CarbonImmutable $keptPast = null;

    /** @var array{read: int, kept: int} how many figures were read, and how many found kept, by this instance */
    private array $tally = ['read' => 0, 'kept' => 0];

    /**
     * The cache as the figures are read ahead with: what is kept counts only
     * while it is kept past the moment given — the next reading ahead — and
     * anything else is read again now, so nothing it keeps lapses before
     * then.
     */
    public static function ahead(CarbonImmutable $until): self
    {
        $cache = new self;
        $cache->keptPast = $until;

        return $cache;
    }

    /**
     * What was read — a tab's figures, or what a filter offers — as kept,
     * or, none kept or asked for afresh, as read now, and kept.
     *
     * @param  string  $name  the tab, or what else is kept
     * @param  array<string, mixed>  $state  everything on the page what is read depends on
     * @param  Closure(): array<array-key, mixed>  $read
     * @return array{data: array<array-key, mixed>, readAt: CarbonImmutable}
     */
    public function remember(string $name, array $state, bool $fresh, Closure $read): array
    {
        $key = self::key($name, $state);
        $kept = $fresh ? null : $this->kept($key);

        if ($kept !== null) {
            return $kept;
        }

        $lock = Cache::lock($key.':reading', self::READING_SECONDS);
        $held = $fresh ? $lock->get() : self::await($lock);

        try {
            // Read meanwhile by whoever was reading them.
            $kept = $fresh ? null : $this->kept($key);

            if ($kept !== null) {
                return $kept;
            }

            // Figures are as of the moment their read began: the visits as they were then.
            $at = CarbonImmutable::now('UTC')->startOfSecond();
            $data = $read();

            // Figures a read begun later kept meanwhile — a Refresh, which never waits for this one — are newer, and stay.
            if (! self::keptLater($key, $at)) {
                Cache::put($key, ['at' => $at->toIso8601String(), 'data' => $data], $at->addMinutes((int) config('analytics.read_cache_minutes')));
            }

            $this->tally['read']++;

            return ['data' => $data, 'readAt' => $at];
        } finally {
            if ($held) {
                $lock->release();
            }
        }
    }

    /**
     * How many figures this instance read, and how many it found kept.
     *
     * @return array{read: int, kept: int}
     */
    public function tally(): array
    {
        return $this->tally;
    }

    /**
     * A segment or tracking link changed what the tabs show: everything kept,
     * on any tab, filter or link details, is read afresh from now on. A new
     * token rather than an increment, which a missing key doesn't take alike
     * on every store.
     */
    public function forgetAll(): void
    {
        Cache::forever(self::VERSION, (string) Str::ulid());
    }

    /**
     * Notes the view an administrator just opened or chose — the period, as
     * the page read it, at the site's address the page was asked at — as
     * their latest; a view no administrator opened or chose for
     * `analytics.read_ahead.idle_hours` is let go. Two administrators noted
     * at the same moment may leave one note, the other's back at their next
     * open: a note only steers what is read ahead.
     */
    public function opened(int $userId, string $period, string $from, string $to): void
    {
        $now = CarbonImmutable::now('UTC');
        $views = self::views($now);
        $views[$userId] = ['origin' => url('/'), 'period' => $period, 'from' => $from, 'to' => $to, 'at' => $now->toIso8601String()];

        Cache::put(self::VIEWS, $views, $now->addHours((int) config('analytics.read_ahead.idle_hours')));
    }

    /**
     * The views whose figures are read ahead, the likeliest first, at most
     * `analytics.read_ahead.views`: at each site's address an administrator
     * who may still open the page opened it at within
     * `analytics.read_ahead.idle_hours`, the page's default period — where an
     * administrator with no period kept lands — then each such
     * administrator's latest period, the latest first. Each with the
     * administrator it is read as, the latest to open it: what a tab shows
     * may depend on what its reader may open. None once nobody opened the
     * page for that long.
     *
     * @return list<array{reader: User, origin: string, period: string, from: string, to: string}>
     */
    public function recentViews(): array
    {
        $views = self::views(CarbonImmutable::now('UTC'));
        uasort($views, fn (array $one, array $other): int => strcmp($other['at'], $one['at']));
        $readers = User::query()->whereKey(array_keys($views))->get()->keyBy('id');
        $read = [];

        foreach ([true, false] as $default) {
            foreach ($views as $userId => $view) {
                $reader = $readers->get($userId);

                if ($reader === null || Gate::forUser($reader)->denies('manage-traffic-sources')) {
                    continue;
                }

                [$period, $from, $to] = $default ? [AnalyticsPeriod::DEFAULT, '', ''] : [$view['period'], $view['from'], $view['to']];
                $read[serialize([$view['origin'], $period, $from, $to])] ??= ['reader' => $reader, 'origin' => $view['origin'], 'period' => $period, 'from' => $from, 'to' => $to];
            }
        }

        return array_slice(array_values($read), 0, max(0, (int) config('analytics.read_ahead.views')));
    }

    /**
     * What was kept under the key, while it counts.
     *
     * @return array{data: array<array-key, mixed>, readAt: CarbonImmutable}|null
     */
    private function kept(string $key): ?array
    {
        $kept = Cache::get($key);

        if (! is_array($kept) || ! is_array($kept['data'] ?? null) || ! is_string($kept['at'] ?? null)) {
            return null;
        }

        $at = CarbonImmutable::parse($kept['at'])->utc();

        if ($this->keptPast !== null && $at->addMinutes((int) config('analytics.read_cache_minutes'))->lessThanOrEqualTo($this->keptPast)) {
            return null;
        }

        $this->tally['kept']++;

        return ['data' => $kept['data'], 'readAt' => $at];
    }

    /**
     * Whether what is kept under the key was read from a later moment than
     * the one given. Looking and keeping are two steps, not one: only two
     * reads finishing within that instant can still leave the earlier's.
     */
    private static function keptLater(string $key, CarbonImmutable $at): bool
    {
        $kept = Cache::get($key);

        return is_array($kept) && is_array($kept['data'] ?? null) && is_string($kept['at'] ?? null)
            && CarbonImmutable::parse($kept['at'])->utc()->greaterThan($at);
    }

    /** Waits for whoever is reading the same figures, at most WAIT_SECONDS: whether the read is now this request's. */
    private static function await(Lock $lock): bool
    {
        try {
            return (bool) $lock->block(self::WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * The latest view of each administrator who opened the page within the
     * idle hours, by administrator.
     *
     * @return array<int, array{origin: string, period: string, from: string, to: string, at: string}>
     */
    private static function views(CarbonImmutable $now): array
    {
        $kept = Cache::get(self::VIEWS);
        $since = $now->subHours((int) config('analytics.read_ahead.idle_hours'))->toIso8601String();
        $views = [];

        foreach (is_array($kept) ? $kept : [] as $userId => $view) {
            if (is_int($userId) && is_array($view) && is_string($view['origin'] ?? null) && is_string($view['period'] ?? null) && is_string($view['from'] ?? null) && is_string($view['to'] ?? null) && is_string($view['at'] ?? null) && $view['at'] > $since) {
                $views[$userId] = ['origin' => $view['origin'], 'period' => $view['period'], 'from' => $view['from'], 'to' => $view['to'], 'at' => $view['at']];
            }
        }

        return $views;
    }

    /** @param  array<string, mixed>  $state */
    private static function key(string $name, array $state): string
    {
        ksort($state);
        $version = Cache::get(self::VERSION);

        return self::PREFIX.':'.(is_string($version) ? $version : '0').':'.$name.':'.hash('xxh128', serialize([url('/'), $state]));
    }
}
