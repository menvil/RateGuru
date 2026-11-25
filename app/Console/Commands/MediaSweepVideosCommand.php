<?php

namespace App\Console\Commands;

use App\Services\Media\VideoMediaSweeper;
use Illuminate\Console\Command;

/**
 * Settles what video processing leaves behind: originals kept their
 * retention period, the files of failed uploads, and uploads whose job was
 * lost (VideoMediaSweeper). Scheduled hourly.
 */
final class MediaSweepVideosCommand extends Command
{
    protected $signature = 'media:sweep-videos';

    protected $description = 'Delete expired video originals and failed uploads\' files, and fail uploads never processed';

    public function handle(VideoMediaSweeper $sweeper): int
    {
        $stuck = $sweeper->failStuck();
        $originals = $sweeper->deleteExpiredOriginals();
        $failed = $sweeper->removeFailedFiles();

        $this->info("Failed {$stuck} upload(s) never processed; deleted {$originals} original(s); removed the files of {$failed} failed upload(s).");

        return self::SUCCESS;
    }
}
