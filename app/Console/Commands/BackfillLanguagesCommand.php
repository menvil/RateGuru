<?php

namespace App\Console\Commands;

use App\Actions\Languages\LanguageCheckQueue;
use App\Actions\Languages\WorkOutPostLanguageAction;
use App\Models\Post;
use Illuminate\Console\Command;

/**
 * Gives every post and comment written before languages were kept a language at once
 * (docs/architecture/language-ranking.md): each text read on this server and queued for OpenAI behind texts written
 * now, then every post's language worked out. Not needed for an installation to get there — languages:work takes
 * those texts up on its own, a few hundred a minute — only to get there now; run again, it reads only what it has not.
 */
final class BackfillLanguagesCommand extends Command
{
    protected $signature = 'languages:backfill';

    protected $description = 'Give the posts and comments written before languages were kept a language, now.';

    public function handle(LanguageCheckQueue $queue, WorkOutPostLanguageAction $workOut): int
    {
        $read = 0;

        while (($batch = $queue->readOlderTexts(500)) > 0) {
            $read += $batch;
        }

        $workedOut = 0;

        Post::withTrashed()->whereNotNull('language_stale_at')->select('id')->lazyById(500)->each(function (Post $post) use ($workOut, &$workedOut): void {
            $workOut->handle((int) $post->id);
            $workedOut++;
        });

        $this->info("Read {$read} texts; worked out the language of {$workedOut} posts. OpenAI checks them in the background, after the texts written now.");

        return self::SUCCESS;
    }
}
