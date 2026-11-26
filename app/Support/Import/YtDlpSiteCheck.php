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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    /** The recent pages a site is asked about, newest first. */
    private const int SAMPLES = 3;

    /** How far back through the imports it looks for them. */
    private const int SCAN_LIMIT = 5000;

    public function __construct(
        private readonly YtDlp $ytDlp,
        private readonly YtDlpSites $sites,
    ) {}

    /**
     * @return array<string, array{status: string, checked_at: string, failures: int}>
     *                                                                                 by extractor: ok, broken, or no_sample (nothing imported from it yet); failures counts the days running it took nothing
     */
    public function run(): array
    {
        $previous = self::results();
        $samples = $this->samples();
        $results = [];

        foreach ($this->sites->extractors() as $extractor) {
            $before = $previous[$extractor] ?? null;
            $failures = (int) ($before['failures'] ?? 0);
            // A second run on the same day — by hand, or overlapping — says
            // nothing new about the site: it counts days, not runs.
            $checkedToday = is_string($before['checked_at'] ?? null) && CarbonImmutable::parse($before['checked_at'])->isSameDay(now());

            if (($samples[$extractor] ?? []) === []) {
                // Nothing to ask about is no news either way: a broken site
                // whose last good pages aged out stays as counted.
                $results[$extractor] = ['status' => 'no_sample', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => $failures];

                continue;
            }

            try {
                $works = $this->anyGivesVideo($samples[$extractor]);
            } catch (MediaToolUnavailableException) {
                // Not the sites: yt-dlp itself. Nothing is counted, and it is
                // said once, as what it is.
                report(YtDlpSiteBrokenException::toolUnavailable());

                return $previous;
            }

            $counted = $works ? 0 : ($checkedToday && ($before['status'] ?? null) === 'broken' ? $failures : $failures + 1);
            $results[$extractor] = ['status' => $works ? 'ok' : 'broken', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => $counted];

            // Said once a day at most: a second run that day reported it already.
            if ($counted >= 2 && ! ($checkedToday && $failures >= 2)) {
                report(YtDlpSiteBrokenException::site($extractor, $counted));
            }
        }

        Cache::store('database')->forever(self::CACHE_KEY, $results);

        return $results;
    }

    /** @return array<string, array{status: string, checked_at: string, failures: int}> the last run's, or none */
    public static function results(): array
    {
        $results = Cache::store('database')->get(self::CACHE_KEY);

        return is_array($results) ? $results : [];
    }

    /**
     * Whether any of the pages still gives a video. A page that times out is
     * passed over for the next; yt-dlp failing to start at all is not the
     * site's doing, and goes up.
     *
     * @param  list<string>  $samples
     *
     * @throws MediaToolUnavailableException
     */
    private function anyGivesVideo(array $samples): bool
    {
        foreach ($samples as $url) {
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
     * to SAMPLES each — in one pass over the newest imports, so a site
     * imported from rarely is still found among busier ones. Only pages yt-dlp
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

            if ($extractor !== null && $detector->detect($url) !== 'direct_video' && count($found[$extractor] ?? []) < self::SAMPLES && ! in_array($url, $found[$extractor] ?? [], true)) {
                $found[$extractor][] = $url;
            }

            $complete = array_filter($wanted, fn (string $name): bool => count($found[$name] ?? []) >= self::SAMPLES);

            if (count($complete) === count($wanted) || ++$scanned >= self::SCAN_LIMIT) {
                break;
            }
        }

        return $found;
    }
}
