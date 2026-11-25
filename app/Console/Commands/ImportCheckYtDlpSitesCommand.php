<?php

namespace App\Console\Commands;

use App\Support\Import\YtDlpSiteCheck;
use Illuminate\Console\Command;

/**
 * Asks yt-dlp, once a day, whether it still takes videos from each of its
 * sites (YtDlpSiteCheck). A site broken two days running is reported; Media
 * Diagnostics shows every site's last result.
 */
final class ImportCheckYtDlpSitesCommand extends Command
{
    protected $signature = 'import:check-ytdlp-sites';

    protected $description = 'Check that yt-dlp still takes videos from each site it may import from';

    public function handle(YtDlpSiteCheck $check): int
    {
        foreach ($check->run() as $extractor => $result) {
            $this->line("{$extractor}: {$result['status']}".($result['failures'] > 0 ? " ({$result['failures']} check(s) running)" : ''));
        }

        return self::SUCCESS;
    }
}
