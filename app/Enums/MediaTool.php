<?php

namespace App\Enums;

/**
 * The host programs the media pipeline runs for video: ffprobe to read what a
 * file holds, ffmpeg to transcode it. install-bootstrap-runtime installs both
 * from Ubuntu's ffmpeg package; config/media.php names the binaries.
 */
enum MediaTool: string
{
    case Ffmpeg = 'ffmpeg';
    case Ffprobe = 'ffprobe';

    public function binary(): string
    {
        return (string) config("media.video.{$this->value}_binary", $this->value);
    }
}
