<?php

namespace App\Console\Commands;

use App\Actions\Languages\QueueLanguageCheckAction;
use App\Actions\Languages\WorkOutPostLanguageAction;
use App\Enums\ContentLanguageCheckPriority;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Services\Languages\LocalLanguageDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives the posts and comments written before languages were kept a language (docs/architecture/language-ranking.md):
 * each text read on this server and queued for OpenAI behind texts written now, then every post's language worked
 * out from its text, author, comments and voters. Run once on an installation, by hand; run again, it picks up only
 * what it has not read yet.
 */
final class BackfillLanguagesCommand extends Command
{
    protected $signature = 'languages:backfill';

    protected $description = 'Give the posts and comments written before languages were kept a language.';

    public function handle(LocalLanguageDetector $detector, QueueLanguageCheckAction $checks, WorkOutPostLanguageAction $workOut): int
    {
        $posts = 0;
        $comments = 0;

        Post::withTrashed()
            ->whereNotIn('id', ContentLanguageCheck::query()->whereNotNull('post_id')->select('post_id'))
            ->select(['id', 'title', 'description'])
            ->lazyById(500)
            ->each(function (Post $post) use ($detector, $checks, &$posts): void {
                $read = $detector->read(trim($post->title."\n".($post->description ?? '')));
                DB::transaction(fn () => $checks->forPost($post, $read, ContentLanguageCheckPriority::Backfill));
                Post::withoutTimestamps(fn () => Post::withTrashed()->whereKey($post->id)->update(['language_stale_at' => now()]));
                $posts++;
            });

        Comment::withTrashed()
            ->whereNotIn('id', ContentLanguageCheck::query()->whereNotNull('comment_id')->select('comment_id'))
            ->select(['id', 'post_id', 'body'])
            ->lazyById(500)
            ->each(function (Comment $comment) use ($detector, $checks, &$comments): void {
                $read = $detector->read((string) $comment->body);
                DB::transaction(function () use ($comment, $read, $checks): void {
                    Comment::withoutTimestamps(fn () => Comment::withTrashed()->whereKey($comment->id)->update(['language' => $read->language()]));
                    $checks->forComment($comment, $read, ContentLanguageCheckPriority::Backfill);
                });
                Post::withoutTimestamps(fn () => Post::withTrashed()->whereKey($comment->post_id)->update(['language_stale_at' => now()]));
                $comments++;
            });

        $workedOut = 0;

        Post::withTrashed()->whereNotNull('language_stale_at')->select('id')->lazyById(500)->each(function (Post $post) use ($workOut, &$workedOut): void {
            $workOut->handle((int) $post->id);
            $workedOut++;
        });

        $this->info("Read {$posts} posts and {$comments} comments; worked out the language of {$workedOut} posts. OpenAI checks them in the background, after the texts written now.");

        return self::SUCCESS;
    }
}
