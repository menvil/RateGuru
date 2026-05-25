<?php

namespace Database\Seeders;

use App\Enums\CommentStatus;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoCommentsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $users = User::query()
            ->where('status', UserStatus::Active)
            ->orderBy('email')
            ->get();

        Post::query()
            ->where('status', PostStatus::Published)
            ->orderBy('published_at')
            ->get()
            ->each(function (Post $post) use ($users) {
                $commenters = $users
                    ->where('id', '!==', $post->user_id)
                    ->take(2)
                    ->values();

                foreach ($commenters as $index => $user) {
                    Comment::query()->updateOrCreate(
                        [
                            'post_id' => $post->id,
                            'user_id' => $user->id,
                            'body' => $this->bodyFor($post, $index),
                        ],
                        [
                            'status' => CommentStatus::Visible,
                        ],
                    );
                }

                $this->refreshCommentsCount($post);
            });
    }

    private function bodyFor(Post $post, int $index): string
    {
        return match ($index) {
            0 => 'Demo comment: this plate is useful for feed and post show checks.',
            default => 'Demo comment for '.$post->title,
        };
    }

    private function refreshCommentsCount(Post $post): void
    {
        $post->forceFill([
            'comments_count' => $post->comments()
                ->where('status', CommentStatus::Visible)
                ->count(),
        ])->save();
    }
}
