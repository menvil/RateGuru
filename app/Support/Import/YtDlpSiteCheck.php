<?php

namespace App\Support\Import;

use App\Enums\MediaType;
use App\Exceptions\Import\YtDlpSiteBrokenException;
use App\Models\MediaAsset;
use App\Services\Media\Exceptions\MediaToolInterruptedException;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Whether yt-dlp still takes videos from each of its sites
 * (import:check-ytdlp-sites, daily). A site is asked about the pages its
 * videos were last imported from — real links that worked — so no list of
 * reference pages has to be kept: one that still gives a video means the
 * site works; when the last few all give none, the extractor most likely
 * broke. Two failed checks running are reported (Sentry, and Nightwatch where
 * it runs); every result is kept for Media Diagnostics.
 */
final class YtDlpSiteCheck
{
    public const string CACHE_KEY = 'import:ytdlp-site-check';

    /** The recent pages a site is asked about, newest first. */
    private const int SAMPLES = 3;

    public function __construct(
        private readonly YtDlp $ytDlp,
        private readonly YtDlpSites $sites,
    ) {}

    /**
     * @return array<string, array{status: string, checked_at: string, failures: int}>
     *                                                                                 by extractor: ok, broken, or no_sample (nothing imported from it yet)
     */
    public function run(): array
    {
        $previous = self::results();
        $results = [];

        foreach ($this->sites->extractors() as $extractor) {
            $samples = $this->samples($extractor);
            $failures = (int) ($previous[$extractor]['failures'] ?? 0);

            if ($samples === []) {
                $results[$extractor] = ['status' => 'no_sample', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => 0];

                continue;
            }

            $works = $this->anyGivesVideo($samples);
            $failures = $works ? 0 : $failures + 1;
            $results[$extractor] = ['status' => $works ? 'ok' : 'broken', 'checked_at' => now()->toIso8601ZuluString(), 'failures' => $failures];

            if ($failures >= 2) {
                report(YtDlpSiteBrokenException::site($extractor, $failures));
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

    /** @param  list<string>  $samples */
    private function anyGivesVideo(array $samples): bool
    {
        foreach ($samples as $url) {
            try {
                if ($this->ytDlp->probe($url) !== null) {
                    return true;
                }
            } catch (MediaToolTimedOutException|MediaToolUnavailableException|MediaToolInterruptedException $exception) {
                Log::warning('YtDlpSiteCheck: yt-dlp could not be run.', ['exception_class' => $exception::class]);

                return false;
            }
        }

        return false;
    }

    /** @return list<string> the pages a site's videos were last imported from */
    private function samples(string $extractor): array
    {
        $urls = [];

        MediaAsset::query()
            ->whereIn('media_type', [MediaType::Video, MediaType::Animation])
            ->whereNotNull('metadata->import->downloaded_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'metadata'])
            ->each(function (MediaAsset $asset) use ($extractor, &$urls): bool {
                $url = $asset->metadata['import']['url'] ?? null;

                if (is_string($url) && $this->sites->extractorFor($url) === $extractor && ! in_array($url, $urls, true)) {
                    $urls[] = $url;
                }

                return count($urls) < self::SAMPLES;
            });

        return $urls;
    }
}
