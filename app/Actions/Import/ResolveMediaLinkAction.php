<?php

namespace App\Actions\Import;

use App\Exceptions\Abuse\RateLimitExceededException;
use App\Exceptions\Import\MediaLinkRefusedException;
use App\Exceptions\Import\UnsafeImportUrlException;
use App\Exceptions\Import\UrlImportDisabledException;
use App\Models\User;
use App\Services\Media\Exceptions\MediaToolInterruptedException;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Support\AbuseGuards\ActionRateLimiter;
use App\Support\AbuseGuards\RateLimitKey;
use App\Support\Import\ImportProviderDetector;
use App\Support\Import\UrlImportValidator;
use App\Support\Import\YtDlp;
use App\Support\Import\YtDlpProbeException;
use App\Support\Import\YtDlpProbeResult;
use App\Support\Import\YtDlpSites;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Decides what a link given to the upload form brings
 * (docs/architecture/media.md, "Posting from a link"):
 *
 *   a picture's link          the picture, fetched as it always was
 *   a video file's link       the video, downloaded once the post exists
 *   a post on X               its video or GIF, otherwise its photo (its own
 *                             reader, which takes X's video best)
 *   any other page            asked of yt-dlp first (YtDlpSites), one rule
 *                             for every site, by what yt-dlp says:
 *                               a video        the video, its length read now
 *                               no video, or a site it does not know
 *                                              its picture (Open Graph), or
 *                                              the link tried as a picture's
 *                               a site it knows but could not read
 *                                              refused, to try again — never
 *                                              its poster posted for its video
 *
 * Counted against the author's link imports before anything is fetched.
 * The form keeps the validation of the field and what the author is told.
 */
final class ResolveMediaLinkAction
{
    /** The sites whose post is where a picture came from, named as the post's source. */
    private const array SOURCE_PROVIDERS = ['x', 'instagram', 'facebook', 'pinterest'];

    public function __construct(
        private readonly ImportProviderDetector $detector,
        private readonly YtDlpSites $sites,
    ) {}

    /**
     * @throws MediaLinkRefusedException for a reason the author can act on
     * @throws RateLimitExceededException
     * @throws UrlImportDisabledException
     * @throws UnsafeImportUrlException
     */
    public function handle(User $author, string $url): ResolvedMediaLink
    {
        app(ActionRateLimiter::class)->hitOrFail(
            key: RateLimitKey::userAction('link-import', $author),
            maxAttempts: (int) config('rate_limits.link_import.max_attempts'),
            decaySeconds: (int) config('rate_limits.link_import.decay_seconds'),
            message: __('ui.rate_limit.uploading'),
        );

        $provider = $this->detector->detect($url);

        if ($provider === 'direct_image') {
            return new ResolvedMediaLink($url, null, null, previewImageUrl: $url);
        }

        // A link to a video file goes to the plain download, whatever site
        // serves it; any other page on one of yt-dlp's sites is asked first,
        // through that site's extractor alone.
        $extractor = $provider === 'direct_video' ? null : $this->sites->extractorFor($url);
        $pageVideo = $extractor === null ? null : $this->pageVideo($author, $url);

        if ($pageVideo !== null) {
            $maxSeconds = (int) config('uploads.videos.max_duration_seconds');

            // Refused here when the site says it is too long. A length it does
            // not say (an Instagram reel read from its page) is held to the
            // limit once the file is there: processing refuses a longer one
            // and tells the author, and the download is capped in size.
            if ($pageVideo->durationSeconds !== null && $pageVideo->durationSeconds > $maxSeconds) {
                throw MediaLinkRefusedException::tooLong($maxSeconds);
            }

            return new ResolvedMediaLink(null, $url, self::withoutQuery($url), previewImageUrl: $pageVideo->thumbnail, durationSeconds: $pageVideo->durationSeconds);
        }

        // No video on the page, or a site yt-dlp does not know: the link
        // goes on as any link.
        $preview = app(ImportFromUrlAction::class)->handle($url);
        $videoUrl = $preview->videoUrl;
        // A link that names no file and is no known site's post may still be
        // a picture's (an image CDN's, "copy image address"): with nothing
        // else to take, it is tried as one, as it always was.
        $imageUrl = $videoUrl === null ? ($preview->imageUrl ?? ($provider === 'open_graph' ? $url : null)) : null;

        if ($videoUrl === null && $imageUrl === null) {
            throw MediaLinkRefusedException::nothingToTake();
        }

        return new ResolvedMediaLink(
            $imageUrl,
            $videoUrl,
            in_array($provider, self::SOURCE_PROVIDERS, true) ? self::withoutQuery($url) : null,
            // A picture shows as itself; a video by its post's still, where it has one.
            previewImageUrl: $imageUrl ?? $preview->imageUrl,
        );
    }

    /**
     * The video yt-dlp finds on a page of one of its sites, or null for a
     * page with no video. yt-dlp unable to answer is a refusal to try later.
     * URL imports must be on, and the link a public address, before yt-dlp is
     * given it.
     *
     * @throws RateLimitExceededException when the author's or the host's probes are all in use
     * @throws MediaLinkRefusedException when yt-dlp knows the site but could not read the page, or found a carousel with a video
     */
    private function pageVideo(User $author, string $url): ?YtDlpProbeResult
    {
        if (! app(ProjectSettingsManager::class)->featureEnabled('allow_url_imports')) {
            throw new UrlImportDisabledException;
        }

        app(UrlImportValidator::class)->validate($url);

        // A probe holds a web worker while the author waits: one per author,
        // and only as many on the host at once as import.ytdlp.probe_slots,
        // so a few accounts cannot take every worker the site has.
        $mine = self::probeLock('ytdlp-probe:user:'.$author->id);
        $slot = $mine->get() ? self::probeSlot() : null;

        if ($slot === null) {
            $mine->release();

            throw RateLimitExceededException::make(__('ui.rate_limit.uploading'), 30);
        }

        try {
            return app(YtDlp::class)->probe($url);
        } catch (YtDlpProbeException $exception) {
            // Not "no video": a carousel with one among its photos, or a
            // page of a site yt-dlp knows that it could not read — never
            // posted as its picture.
            throw $exception->carouselWithVideo ? MediaLinkRefusedException::carouselWithVideo() : MediaLinkRefusedException::couldNotRead();
        } catch (MediaToolTimedOutException|MediaToolUnavailableException|MediaToolInterruptedException $exception) {
            // yt-dlp could not answer — never the same as "this page has no
            // video": an Instagram reel would otherwise go on as its poster.
            report($exception);

            throw MediaLinkRefusedException::couldNotRead();
        } finally {
            $slot->release();
            $mine->release();
        }
    }

    /** A free one of the host's probe slots, held, or null when all are taken. */
    private static function probeSlot(): ?Lock
    {
        for ($slot = 1; $slot <= max(1, (int) config('import.ytdlp.probe_slots')); $slot++) {
            $lock = self::probeLock("ytdlp-probe:slot:{$slot}");

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /** A lock on the database store, which every web worker on every host shares. */
    private static function probeLock(string $key): Lock
    {
        $store = Cache::store('database')->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException('The "database" cache store does not support locks.');
        }

        return $store->lock($key, 30);
    }

    /** Where it came from, without its query: it may carry a token or tracking, never meant to be public. */
    private static function withoutQuery(string $url): ?string
    {
        $bare = strtok($url, '?#') ?: null;

        // A YouTube watch page is the video only with its v; nothing else of
        // the query is kept.
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        if ($bare !== null && ($host === 'youtube.com' || str_ends_with($host, '.youtube.com')) && parse_url($bare, PHP_URL_PATH) === '/watch'
            && is_string($query['v'] ?? null) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $query['v']) === 1) {
            return $bare.'?v='.$query['v'];
        }

        return $bare;
    }
}
