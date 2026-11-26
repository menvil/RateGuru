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
        private readonly YtDlpIsolation $isolation,
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

        // Where yt-dlp runs as its own account, it may write here, and only
        // here, for this run.
        $this->isolation->share($directory);

        $result = $this->runner->run(MediaTool::YtDlp, [
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

        if (! $result->succeeded()) {
            return null;
        }

        // The finished file alone: download.<ext>, never a part yt-dlp left
        // behind (download.f137.mp4, a fragment) when joining failed.
        $files = array_values(array_filter(
            glob($directory.'/download.*') ?: [],
            fn (string $path): bool => is_file($path) && preg_match('#/download\.[a-z0-9]+$#', $path) === 1,
        ));

        return count($files) === 1 ? $files[0] : null;
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

    /**
     * yt-dlp's own reading: a codec it was not told is unknown — there may
     * well be a picture — and only an explicit 'none' means there is none.
     * RedGifs and YouPorn give their MP4s no codec at all, Coub gives null.
     *
     * @param  array<string, mixed>  $info
     */
    private static function hasVideo(array $info): bool
    {
        $formats = is_array($info['formats'] ?? null) ? array_filter($info['formats'], 'is_array') : [];

        if ($formats === []) {
            return ($info['vcodec'] ?? null) !== 'none' && (isset($info['url']) || isset($info['requested_formats']));
        }

        foreach ($formats as $format) {
            if (($format['vcodec'] ?? null) !== 'none') {
                return true;
            }
        }

        return false;
    }
}
