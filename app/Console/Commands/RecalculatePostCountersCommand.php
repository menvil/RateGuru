<?php

namespace App\Console\Commands;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Models\Post;
use Illuminate\Console\Command;

class RecalculatePostCountersCommand extends Command
{
    protected $signature = 'rateguru:recalculate-post-counters {--post-id=}';

    protected $description = 'Recalculate vote counters for posts.';

    public function handle(RecalculatePostCountersAction $action): int
    {
        $postId = $this->option('post-id');

        $query = Post::query();

        if ($postId) {
            $query->whereKey($postId);
        }

        $count = 0;
        $errors = [];

        $query->chunkById(100, function ($posts) use ($action, &$count, &$errors) {
            foreach ($posts as $post) {
                try {
                    $action->handle($post);
                    $count++;
                } catch (\Throwable $e) {
                    // A single broken post must not abort the whole run:
                    // record it and continue with the rest of the batch.
                    $errors[$post->getKey()] = $e->getMessage();
                }
            }
        });

        $this->info("Recalculated counters for {$count} posts.");

        if ($errors !== []) {
            foreach ($errors as $postId => $message) {
                $this->error("Post {$postId}: {$message}");
            }

            $this->error('Failed to recalculate '.count($errors).' posts.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
