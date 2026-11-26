<?php

namespace App\Exceptions\Import;

use RuntimeException;

/**
 * A link the upload form will not post from, for a reason its author can
 * act on; the message is already in the author's language.
 */
final class MediaLinkRefusedException extends RuntimeException
{
    /** A page on a site where every page is a video gave none. */
    public static function noVideoOnPage(): self
    {
        return new self(__('import.errors.fetch_failed'));
    }

    /** A page's video whose length the site does not say: the download could not hold it to the limit. */
    public static function unknownLength(): self
    {
        return new self(__('ui.upload.error_invalid_video'));
    }

    public static function tooLong(int $maxSeconds): self
    {
        return new self(__('ui.upload.error_video_too_long', ['seconds' => $maxSeconds]));
    }

    /** yt-dlp could not answer — unavailable, too slow, cut short: to be tried again later. */
    public static function couldNotRead(): self
    {
        return new self(__('import.errors.fetch_failed'));
    }

    /** A carousel with a video among its photos: one of several is not taken. */
    public static function carouselWithVideo(): self
    {
        return new self(__('import.errors.unsupported'));
    }

    /** Nothing on the page to take: no video, no picture. */
    public static function nothingToTake(): self
    {
        return new self(__('import.errors.unsupported'));
    }
}
