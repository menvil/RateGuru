<?php

namespace Database\Seeders;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoVotesSeeder extends Seeder
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
                $voters = $users
                    ->where('id', '!=', $post->user_id)
                    ->take(8)
                    ->values();

                foreach ($voters as $index => $user) {
                    PostVote::query()->updateOrCreate(
                        ['post_id' => $post->id, 'user_id' => $user->id],
                        ['type' => in_array($index, [3, 6], true) ? VoteType::Down : VoteType::Up],
                    );

                    OriginVote::query()->updateOrCreate(
                        ['post_id' => $post->id, 'user_id' => $user->id],
                        ['origin' => $index % 2 === 0 ? $post->origin_truth : OriginType::Restaurant],
                    );

                    CuisineVote::query()->updateOrCreate(
                        ['post_id' => $post->id, 'user_id' => $user->id],
                        ['cuisine' => $index === 0 ? $post->cuisine_truth : $this->fallbackCuisine($index)],
                    );
                }

                app(RecalculatePostCountersAction::class)->handle($post);
                app(RecalculatePostScoreAction::class)->handle($post);
            });
    }

    private function fallbackCuisine(int $index): CuisineType
    {
        return match ($index % 4) {
            1 => CuisineType::Italian,
            2 => CuisineType::Asian,
            3 => CuisineType::Mexican,
            default => CuisineType::American,
        };
    }
}
