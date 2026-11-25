<?php

namespace App\Actions\Languages;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\ContentLanguageCheck;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use App\Support\Languages\LanguageProfile;
use Illuminate\Support\Facades\DB;

/**
 * A post's language worked out again from everything that tells it now: the check of its text, its author's
 * language, its visible comments and the languages of those who voted on it (LanguageProfile).
 *
 * In a transaction of its own, the post locked first and saved once; the signals are read without locks, so it
 * never takes an account or a comment after the post. A vote or a comment that lands meanwhile waits for the post
 * and marks it again.
 */
final class WorkOutPostLanguageAction
{
    public function handle(int $postId): void
    {
        DB::transaction(function () use ($postId): void {
            $post = Post::withTrashed()->whereKey($postId)->lockForUpdate()->first();

            if ($post === null) {
                return;
            }

            $profile = new LanguageProfile;
            $check = ContentLanguageCheck::query()->where('post_id', $post->id)->first();

            if ($check !== null) {
                $profile->text($check->language(), $check->weight());
            }

            $profile->author(User::query()->whereKey($post->user_id)->value('locale'));

            $comments = Comment::query()
                ->where('post_id', $post->id)
                ->where('status', CommentStatus::Visible)
                ->get(['id', 'user_id', 'language']);
            $checks = ContentLanguageCheck::query()->whereIn('comment_id', $comments->modelKeys())->get()->keyBy('comment_id');
            $writers = User::query()->whereIn('id', $comments->pluck('user_id')->unique()->all())->pluck('locale', 'id');

            foreach ($comments as $comment) {
                $commentCheck = $checks->get($comment->id);
                // A comment written before the checks existed counts by the language it was given, in full.
                [$language, $weight] = $commentCheck !== null
                    ? [$commentCheck->language(), $commentCheck->weight()]
                    : [$comment->language, $comment->language === null ? 0.0 : 1.0];

                $profile->comment($language, $weight, $writers->get($comment->user_id));
            }

            PostVote::query()
                ->join('users', 'users.id', '=', 'post_votes.user_id')
                ->where('post_votes.post_id', $post->id)
                ->whereNotNull('users.locale')
                ->pluck('users.locale')
                ->each(fn (string $locale) => $profile->vote($locale));

            Post::withoutTimestamps(fn () => $post->forceFill([
                'language' => $profile->language(),
                'language_share' => $profile->share(),
                'language_stale_at' => null,
            ])->save());
        });
    }
}
