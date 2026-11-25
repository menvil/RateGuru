<?php

namespace App\Actions\Languages;

use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * An author's language counts towards their posts' language: when it changes — chosen in the header or the
 * profile, or let go with an account's anonymisation — their posts are worked out again in the background
 * (languages:work). Once the caller's transaction commits, in one statement of its own: it never adds a second
 * update of a post to a transaction, nor locks posts while the author's row is held.
 */
final class MarkAuthorPostsLanguageStaleAction
{
    public function handle(int $authorId): void
    {
        DB::afterCommit(fn () => Post::withoutTimestamps(fn () => Post::query()
            ->where('user_id', $authorId)
            ->whereNull('language_stale_at')
            ->update(['language_stale_at' => now()])));
    }
}
