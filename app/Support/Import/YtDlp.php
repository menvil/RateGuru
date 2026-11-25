<?php

namespace App\Support\Import;

use App\Enums\MediaTool;
use App\Services\Media\MediaToolRunner;
use Symfony\Component\Process\ExecutableFinder;

/**
 * yt-dlp, run only for a link to one of YtDlpSites' sites and only through
 * that site's extractor (--use-extractors): its generic extractor, which
 * would open any address, is never among them. Every run ignores user and
 * system configuration, plugins and the cache, takes one video (no
 * playlist), and gets the link after `--`, so nothing in it is an option.
 */
final class YtDlp
{
    public function __construct(
        private readonly MediaToolRunner $runner,
        private readonly YtDlpSites $sites,
    ) {}

    /**
     * The page's video as yt-dlp sees it, or null when it has none it may
     * take (not one of the sites, no video — a photo post — or yt-dlp
     * failing): the caller then treats the link as it would without it.
     */
    public function probe(string $url): ?YtDlpProbeResult
    {
        $extractor = $this->sites->extractorFor($url);

        if ($extractor === null) {
            return null;
        }

        $result = $this->runner->run(MediaTool::YtDlp, [
            ...$this->baseOptions($extractor),
            '--dump-single-json',
            '--skip-download',
            '--',
            $url,
        ], max(1, (int) config('import.ytdlp.probe_timeout_seconds')));

        $info = $result->succeeded() ? json_decode($result->output, true) : null;

        if (! is_array($info) || ($info['_type'] ?? 'video') !== 'video' || ! self::hasVideo($info)) {
            return null;
        }

        return new YtDlpProbeResult(
            extractor: is_string($info['extractor_key'] ?? null) ? $info['extractor_key'] : $extractor,
            title: is_string($info['title'] ?? null) ? $info['title'] : null,
            durationSeconds: is_numeric($info['duration'] ?? null) ? (int) ceil((float) $info['duration']) : null,
            approximateBytes: is_numeric($info['filesize'] ?? $info['filesize_approx'] ?? null) ? (int) ($info['filesize'] ?? $info['filesize_approx']) : null,
        );
    }

    /**
     * Downloads the page's video into $directory as an MP4 of at most 1080
     * lines, no longer and no larger than an upload may be, and returns its
     * path — or null when yt-dlp took nothing (the site refused, or the video
     * was too long or too large for its filters).
     */
    public function download(string $url, string $directory): ?string
    {
        $extractor = $this->sites->extractorFor($url);

        if ($extractor === null) {
            return null;
        }

        $maxMegabytes = intdiv((int) config('uploads.videos.max_kilobytes'), 1024);
        $maxSeconds = (int) config('uploads.videos.max_duration_seconds');

        $this->runner->run(MediaTool::YtDlp, [
            ...$this->baseOptions($extractor),
            '--no-part',
            '--no-progress',
            '--retries', '2',
            '--max-filesize', "{$maxMegabytes}M",
            '--match-filters', "duration<={$maxSeconds}",
            '-f', 'bv*[height<=1080][ext=mp4]+ba[ext=m4a]/b[height<=1080][ext=mp4]/bv*[height<=1080]+ba/b[height<=1080]/b',
            '--merge-output-format', 'mp4',
            ...$this->ffmpegLocation(),
            '-o', $directory.'/download.%(ext)s',
            '--',
            $url,
        ], max(1, (int) config('import.ytdlp.download_timeout_seconds')));

        $files = array_values(array_filter(glob($directory.'/download.*') ?: [], 'is_file'));

        return $files[0] ?? null;
    }

    /** @return list<string> */
    private function baseOptions(string $extractor): array
    {
        return [
            '--ignore-config',
            '--no-plugin-dirs',
            '--no-cache-dir',
            '--use-extractors', $extractor,
            '--no-playlist',
            '--socket-timeout', '15',
        ];
    }

    /**
     * The pinned ffmpeg, for joining a separate picture and sound: a queue
     * worker's PATH need not lead to it.
     *
     * @return list<string>
     */
    private function ffmpegLocation(): array
    {
        $configured = MediaTool::Ffmpeg->binary();
        $found = str_contains($configured, '/')
            ? $configured
            : (new ExecutableFinder)->find($configured, null, (array) config('media.video.search_directories', []));

        return $found === null ? [] : ['--ffmpeg-location', $found];
    }

    /** @param  array<string, mixed>  $info */
    private static function hasVideo(array $info): bool
    {
        if (is_string($info['vcodec'] ?? null)) {
            return $info['vcodec'] !== 'none';
        }

        foreach (is_array($info['formats'] ?? null) ? $info['formats'] : [] as $format) {
            if (is_array($format) && ($format['vcodec'] ?? 'none') !== 'none') {
                return true;
            }
        }

        return false;
    }
}
