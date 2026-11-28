<?php

namespace App\Support\Import;

use App\Enums\MediaTool;
use App\Exceptions\Import\ImportFetchException;
use App\Services\Media\MediaToolRunner;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

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
        private readonly ImportRefusalMonitor $refusals,
    ) {}

    /**
     * The page's video as yt-dlp sees it, or null when it surely has none —
     * not one of the sites, a photo post, a carousel of photos: the caller
     * then treats the link as it would without it.
     *
     * @throws YtDlpProbeException when yt-dlp could not say (it failed, or
     *                             answered nothing readable), or found a carousel with a video
     *                             among its photos — never taken for a page with no video
     */
    public function probe(string $url, ?int $timeoutSeconds = null): ?YtDlpProbeResult
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
        ], max(1, $timeoutSeconds ?? (int) config('import.ytdlp.probe_timeout_seconds')));

        if (! $result->succeeded()) {
            // yt-dlp's own answer for a post with no video in it — an
            // Instagram photo post — is the one failure that says something.
            if (self::saysNoVideo($result->errorOutput)) {
                return null;
            }

            // Asked with every extractor, a page none of them knows is simply
            // not a video site's: it goes on as any link, and is not logged.
            // Without the generic extractor yt-dlp says no extractor matched;
            // "Unsupported URL" is what one that matched says of the page.
            if ($extractor === YtDlpSites::ANY && preg_match('/^ERROR: (?:Unsupported URL|No suitable extractor(?: \([^)]*\))? found for URL)/m', $result->errorOutput) === 1) {
                return null;
            }

            if (self::saysRefused($result->errorOutput)) {
                $this->refusals->record($url, self::lastLine($result->errorOutput));

                throw YtDlpProbeException::refused($extractor);
            }

            throw self::unread($extractor, self::lastLine($result->errorOutput));
        }

        $info = json_decode($result->output, true);

        if (! is_array($info)) {
            throw self::unread($extractor, 'an answer that is not JSON');
        }

        $type = $info['_type'] ?? 'video';

        // A carousel: photos alone go on as a photo post; one with a video
        // among them is refused, never posted as its first picture. A
        // carousel that lists nothing, or an entry naming no media of its
        // own, says nothing.
        if ($type === 'playlist') {
            $entries = is_array($info['entries'] ?? null) ? array_values($info['entries']) : [];

            if ($entries === []) {
                throw self::unread($extractor, 'a carousel listing nothing');
            }

            $video = false;

            foreach ($entries as $entry) {
                if (! is_array($entry) || ! self::listsMedia($entry)) {
                    throw self::unread($extractor, 'a carousel entry naming no media');
                }

                $video = $video || self::hasVideo($entry);
            }

            return $video ? throw YtDlpProbeException::carouselWithVideo($extractor) : null;
        }

        // Only an answer that lists the page's media can say there is no
        // video among it: an answer of another kind, or one naming no media
        // at all, is no answer.
        if ($type !== 'video' || ! self::listsMedia($info)) {
            throw self::unread($extractor, 'an answer naming no media');
        }

        if (! self::hasVideo($info)) {
            return null;
        }

        return new YtDlpProbeResult(
            extractor: is_string($info['extractor_key'] ?? null) ? $info['extractor_key'] : $extractor,
            title: is_string($info['title'] ?? null) ? $info['title'] : null,
            durationSeconds: is_numeric($info['duration'] ?? null) ? (int) ceil((float) $info['duration']) : null,
            approximateBytes: is_numeric($info['filesize'] ?? $info['filesize_approx'] ?? null) ? (int) ($info['filesize'] ?? $info['filesize_approx']) : null,
            thumbnail: is_string($info['thumbnail'] ?? null) && str_starts_with($info['thumbnail'], 'https://') ? $info['thumbnail'] : null,
        );
    }

    /**
     * Downloads the page's video into $directory as an MP4 of at most 1080
     * lines, no longer and no larger than an upload may be, and returns its
     * path — or null when yt-dlp ran and took nothing it may (the page has
     * no video, or it was too long or too large for its filters).
     *
     * @throws ImportFetchException when yt-dlp failed, for the job to try once more
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

        try {
            $result = $this->runner->run(MediaTool::YtDlp, [
                ...$this->baseOptions($extractor),
                '--no-part',
                '--no-progress',
                '--retries', '2',
                '--max-filesize', "{$maxMegabytes}M",
                // A length the site says is held to the limit here; one it does
                // not say passes (`?`), and processing holds the file to it.
                '--match-filters', "duration<=?{$maxSeconds}",
                // A silent video has no audio to join: its video alone, last.
                '-f', 'bv*[height<=1080][ext=mp4]+ba[ext=m4a]/b[height<=1080][ext=mp4]/bv*[height<=1080]+ba/b[height<=1080]/bv*[height<=1080]/b',
                '--merge-output-format', 'mp4',
                ...$this->ffmpegLocation(),
                '-o', $directory.'/download.%(ext)s',
                '--',
                $url,
            ], max(1, (int) config('import.ytdlp.download_timeout_seconds')));
        } catch (Throwable $exception) {
            // Closed to the account before the job removes it: nothing it
            // started, past a timeout's kill, goes on writing there.
            $this->isolation->close($directory);

            throw $exception;
        }

        if (! $result->succeeded()) {
            $this->isolation->take($directory, null);

            // A page with no video is final; anything else yt-dlp failed at
            // — the site slow or refusing for a while — may pass.
            if (self::saysNoVideo($result->errorOutput)) {
                return null;
            }

            if (self::saysRefused($result->errorOutput)) {
                $this->refusals->record($url, self::lastLine($result->errorOutput));
            }

            throw ImportFetchException::connectionError($url, 'yt-dlp could not take the video from that page.');
        }

        // The finished file alone: download.<ext>, never a part yt-dlp left
        // behind (download.f137.mp4, a fragment) when joining failed. Taken
        // from the account, where it ran as one, so the worker stores its own.
        $files = array_values(array_filter(
            glob($directory.'/download.*') ?: [],
            fn (string $path): bool => ! is_link($path) && is_file($path) && preg_match('#/download\.[a-z0-9]+$#', $path) === 1,
        ));

        return $this->isolation->take($directory, count($files) === 1 ? $files[0] : null);
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
     * A page yt-dlp could not read, logged with why — its last error line,
     * or what was wrong with its answer — so the next one can be told apart
     * (a site asking for a sign-in, an extractor broken by a change).
     */
    private static function unread(string $extractor, string $why): YtDlpProbeException
    {
        Log::warning('YtDlp: a page could not be read.', ['extractor' => $extractor, 'why' => $why]);

        return YtDlpProbeException::failed($extractor);
    }

    /** The last line yt-dlp wrote to its error output, bounded. */
    private static function lastLine(string $errorOutput): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $errorOutput) ?: []), fn (string $line): bool => $line !== ''));

        return mb_substr($lines === [] ? 'no error output' : $lines[array_key_last($lines)], 0, 300);
    }

    /**
     * Whether yt-dlp's error says the site refused this server — too many
     * requests, forbidden, a check that it is no robot, a rate limit —
     * rather than that the page is private, gone or not a video's.
     */
    private static function saysRefused(string $errorOutput): bool
    {
        return preg_match('/HTTP Error (?:403|429)|Too Many Requests|confirm (?:you|that you)(?:\x{2019}|\')?re not a bot|rate[- ]limit/iu', $errorOutput) === 1;
    }

    /** Whether yt-dlp's error says the page holds no video at all, rather than that it failed. */
    private static function saysNoVideo(string $errorOutput): bool
    {
        return preg_match('/there is no video in this post|no video formats found|does not contain (?:a |any )?video/i', $errorOutput) === 1;
    }

    /**
     * Whether an answer names media of its own — a format, a requested
     * format, an address — and so can say whether any of it is video.
     *
     * @param  array<mixed>  $info
     */
    private static function listsMedia(array $info): bool
    {
        $formats = is_array($info['formats'] ?? null) ? array_filter($info['formats'], 'is_array') : [];
        $requested = is_array($info['requested_formats'] ?? null) ? array_filter($info['requested_formats'], 'is_array') : [];

        return $formats !== [] || $requested !== [] || (is_string($info['url'] ?? null) && $info['url'] !== '');
    }

    /**
     * yt-dlp's own reading: a codec it was not told is unknown — there may
     * well be a picture — and only an explicit 'none' means there is none.
     * RedGifs gives its MP4s no codec at all.
     *
     * @param  array<mixed>  $info
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
