<?php

namespace App\Enums;

/**
 * The host programs the media pipeline runs for video: ffprobe to read what a
 * file holds, ffmpeg to transcode it, yt-dlp to fetch a video from a site's
 * page. install-bootstrap-runtime installs pinned builds of each;
 * config/media.php and config/import.php name the binaries.
 */
enum MediaTool: string
{
    case Ffmpeg = 'ffmpeg';
    case Ffprobe = 'ffprobe';
    case YtDlp = 'yt-dlp';

    public function binary(): string
    {
        return match ($this) {
            self::YtDlp => (string) config('import.ytdlp.binary', 'yt-dlp'),
            default => (string) config("media.video.{$this->value}_binary", $this->value),
        };
    }
}
