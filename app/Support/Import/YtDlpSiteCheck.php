<?php

namespace App\Support\Import;

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Exceptions\Import\YtDlpSiteBrokenException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use Carbon\CarbonInterface;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Whether yt-dlp still takes videos from each of its sites
 * (import:check-ytdlp-sites, daily). A site is asked about the pages its
 * videos were last imported from — real links that worked — so no list of
 * reference pages has to be kept: one that still gives a video means the
 * site works; when the last few all give none, the extractor most likely
 * broke. Two days running of none are reported (Sentry, and Nightwatch where
 * it runs); every result is kept for Media Diagnostics.
 */
final class YtDlpSiteCheck
{
    public const string CACHE_KEY = 'import:ytdlp-site-check';

    /** The lock one run holds the check by. */
    public const string LOCK_NAME = self::CACHE_KEY.':running';

    /** The recent pages a site is asked about, newest first. */
    private const int SAMPLES = 3;

    /** Days running a site takes nothing before it is reported. */
    public const int REPORT_AFTER_DAYS = 2;

    /** How long one run holds the check: a run that died frees it in time. */
    private const int LOCK_SECONDS = 1800;

    /**
     * How long a run may go on asking: it asks no page after this. With the
     * page asked last (import.ytdlp.check_timeout_seconds at most) it still
     * ends well inside LOCK_SECONDS.
     */
    private const int RUN_SECONDS = 1200;

    public function __construct(
        private readonly YtDlp $ytDlp,
        private readonly YtDlpSites $sites,
    ) {}

    /**
     * One run at a time, however it is started — the schedule or by hand —
     * so no two read the same day's counts and both report a site.
     *
     * @return array<string, array{status: string, checked_at: string, failures: int, failed_on: ?string}>|null
     *                                                                                                          by extractor: ok, broken, or no_sample (nothing imported from it found); failures counts the days running it took nothing, failed_on the last of them. Null while another run holds the check.
     */
    public function run(): ?array
    {
        $store = Cache::store('database')->getStore();

        // The save is fenced by writing the results and checking the lock in
        // one transaction, which needs them on one connection: a lock kept on
        // another (DB_CACHE_LOCK_CONNECTION) could not be, so it is refused.
        if ($store instanceof DatabaseStore && $store->getLockConnection() !== null && $store->getLockConnection() !== $store->getConnection()) {
            throw new LogicException('The yt-dlp site check keeps its lock and its results on one connection: the database cache store\'s lock_connection must be unset, or the same as its connection.');
        }

        $lock = $store instanceof LockProvider ? $store->lock(self::LOCK_NAME, self::LOCK_SECONDS) : null;

        if ($lock !== null && ! $lock->get()) {
            return null;
        }

        try {
            return $this->check($lock);
        } finally {
            $lock?->release();
        }
    }

    /**
     * Whether a site's result has been reported: broken on enough days
     * running. What Media Diagnostics marks as broken rather than failing.
     *
     * @param  array{status: string, failures: int}  $result
     */
    public static function reported(array $result): bool
    {
        return $result['status'] === 'broken' && $result['failures'] >= self::REPORT_AFTER_DAYS;
    }

    /** @return array<string, array{status: string, checked_at: string, failures: int, failed_on: ?string}> */
    private function check(?Lock $lock): array
    {
        $deadline = now()->addSeconds(self::RUN_SECONDS);
        $previous = self::results();
        $samples = $this->samples();
        $results = [];
        $reports = [];
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();

        foreach ($this->sites->extractors() as $extractor) {
            $before = $previous[$extractor] ?? null;
            $failures = (int) ($before['failures'] ?? 0);
            $failedOn = is_string($before['failed_on'] ?? null) ? $before['failed_on'] : null;

            if (($samples[$extractor] ?? []) === []) {
                // Nothing to ask about is no news either way: a broken site
                // whose last good pages aged out stays as counted.
                $results[$extractor] = ['status' => 'no_sample', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => $failures, 'failed_on' => $failedOn];

                continue;
            }

            try {
                $works = $this->anyGivesVideo($samples[$extractor], $deadline);
            } catch (MediaToolUnavailableException) {
                // Not the sites: yt-dlp itself. Nothing is counted or said of
                // any site — not even one already asked — and it is said once,
                // as what it is.
                report(YtDlpSiteBrokenException::toolUnavailable());

                return $previous;
            }

            // Out of time: a run this slow may outlast its lock, so it
            // counts and says nothing of any site — and says that once.
            if ($works === null) {
                report(YtDlpSiteBrokenException::outOfTime());

                return $previous;
            }

            if ($works) {
                $results[$extractor] = ['status' => 'ok', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => 0, 'failed_on' => null];

                continue;
            }

            // It counts days, not runs: a failure today counted already stays
            // as it is. Days running means the failure before was yesterday;
            // after a day nobody checked, or one it worked, it starts again.
            $counted = match ($failedOn) {
                $today => $failures,
                $yesterday => $failures + 1,
                default => 1,
            };
            $results[$extractor] = ['status' => 'broken', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => $counted, 'failed_on' => $today];

            // Said once a day at most: on the day it is counted.
            if ($failedOn !== $today && $counted >= self::REPORT_AFTER_DAYS) {
                $reports[] = YtDlpSiteBrokenException::site($extractor, $counted);
            }
        }

        // A run whose lock lapsed — another may have run since — leaves that
        // one's results as they are, and says nothing.
        if (! $this->saveWhileHeld($lock, $results)) {
            Log::warning('YtDlpSiteCheck: the run outlasted its lock; its results are dropped.');

            return self::results();
        }

        // Only once the run is saved: one that gave up on the tool part way
        // never said anything of a site it had asked.
        foreach ($reports as $report) {
            report($report);
        }

        return $results;
    }

    /** @return array<string, array{status: string, checked_at: string, failures: int, failed_on: ?string}> the last run's, or none */
    public static function results(): array
    {
        $results = Cache::store('database')->get(self::CACHE_KEY);

        return is_array($results) ? $results : [];
    }

    /**
     * Saves the run's results only while it still holds its lock — written
     * and checked as one transaction, committed only if it does. The results
     * are written first: on SQLite, which takes no row locks, that write takes
     * the one writer's lock to the commit; elsewhere the check then holds the
     * lock's row (FOR UPDATE). Either way another run can take the lock only
     * once this transaction ends — after these results are in, which it then
     * reads, or rolled back — so a run paused after its check never writes
     * over a later one.
     *
     * @param  array<string, array{status: string, checked_at: string, failures: int, failed_on: ?string}>  $results
     */
    private function saveWhileHeld(?Lock $lock, array $results): bool
    {
        $store = Cache::store('database')->getStore();

        if (! $lock instanceof CacheLock || ! $store instanceof DatabaseStore) {
            Cache::store('database')->forever(self::CACHE_KEY, $results);

            return true;
        }

        // The lock's own connection, which run() made sure is the results' too.
        $connection = $store->getConnection();
        $connection->beginTransaction();

        try {
            Cache::store('database')->forever(self::CACHE_KEY, $results);

            $held = $connection->table((string) (config('cache.stores.database.lock_table') ?: 'cache_locks'))
                ->where('key', $store->getPrefix().self::LOCK_NAME)
                ->where('owner', $lock->owner())
                ->where('expiration', '>', now()->getTimestamp())
                ->lockForUpdate()
                ->value('owner') !== null;

            $held ? $connection->commit() : $connection->rollBack();

            return $held;
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Whether any of the pages still gives a video, or null when the run's
     * time was up before a page it needed to ask. A page that times out is
     * passed over for the next; yt-dlp failing to start at all is not the
     * site's doing, and goes up.
     *
     * @param  list<string>  $samples
     *
     * @throws MediaToolUnavailableException
     */
    private function anyGivesVideo(array $samples, CarbonInterface $deadline): ?bool
    {
        foreach ($samples as $url) {
            if (now()->greaterThan($deadline)) {
                return null;
            }

            try {
                if ($this->ytDlp->probe($url, max(1, (int) config('import.ytdlp.check_timeout_seconds'))) !== null) {
                    return true;
                }
            } catch (YtDlpProbeException $exception) {
                // A carousel with a video in it: the extractor read the page
                // and found one. A failed read: this page gave nothing.
                if ($exception->carouselWithVideo) {
                    return true;
                }
            } catch (MediaToolTimedOutException) {
                Log::warning('YtDlpSiteCheck: a page timed out; trying the next.');
            }
        }

        return false;
    }

    /**
     * The pages each site's videos were last imported from, newest first, up
     * to SAMPLES each — in one pass over the newest imports, at most
     * import.ytdlp.check_scan_limit of them, so a site imported from rarely
     * is still found among busier ones. Only pages yt-dlp
     * itself took (a link straight to a file went through the plain download,
     * and its site's extractor would refuse it), and only those of posts still
     * on the site: a source taken down is never asked for again.
     *
     * @return array<string, list<string>>
     */
    private function samples(): array
    {
        $wanted = $this->sites->extractors();
        $detector = app(ImportProviderDetector::class);
        $found = [];
        $scanned = 0;

        $imports = MediaAsset::query()
            ->select(['id', 'metadata'])
            ->where('status', MediaStatus::Ready)
            ->whereIn('media_type', [MediaType::Video, MediaType::Animation])
            ->whereNotNull('metadata->import->downloaded_at')
            ->whereIn('id', Post::query()->select('image_asset_id')->where('status', PostStatus::Published))
            ->lazyByIdDesc(500);

        foreach ($imports as $asset) {
            $url = $asset->metadata['import']['url'] ?? null;
            $extractor = is_string($url) ? $this->sites->extractorFor($url) : null;
            // The named sites alone are checked: any other page's site is
            // not one this knows to ask about.
            $extractor = $this->sites->isNamed($extractor) ? $extractor : null;

            if ($extractor !== null && $detector->detect($url) !== 'direct_video' && count($found[$extractor] ?? []) < self::SAMPLES && ! in_array($url, $found[$extractor] ?? [], true)) {
                $found[$extractor][] = $url;
            }

            $complete = array_filter($wanted, fn (string $name): bool => count($found[$name] ?? []) >= self::SAMPLES);

            if (count($complete) === count($wanted) || ++$scanned >= max(1, (int) config('import.ytdlp.check_scan_limit'))) {
                break;
            }
        }

        return $found;
    }
}
