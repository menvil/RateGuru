<?php

namespace Database\Seeders;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\PostStatus;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingVote;
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
        $ratingGroups = RatingGroup::query()
            ->active()
            ->with(['options' => fn ($query) => $query->active()->ordered()])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (RatingGroup $group): bool => $group->options->isNotEmpty());

        Post::query()
            ->where('status', PostStatus::Published)
            ->orderBy('published_at')
            ->get()
            ->each(function (Post $post) use ($ratingGroups, $users) {
                $voters = $users
                    ->where('id', '!=', $post->user_id)
                    ->take(8)
                    ->values();

                foreach ($voters as $index => $user) {
                    PostVote::query()->updateOrCreate(
                        ['post_id' => $post->id, 'user_id' => $user->id],
                        ['type' => in_array($index, [3, 6], true) ? VoteType::Down : VoteType::Up],
                    );

                    foreach ($ratingGroups as $groupIndex => $group) {
                        $option = $group->options[($index + $groupIndex) % $group->options->count()] ?? null;

                        if ($option === null) {
                            continue;
                        }

                        RatingVote::query()->updateOrCreate(
                            [
                                'post_id' => $post->id,
                                'user_id' => $user->id,
                                'rating_group_id' => $group->id,
                            ],
                            ['rating_option_id' => $option->id],
                        );
                    }
                }

                app(RecalculatePostCountersAction::class)->handle($post);
                app(RecalculatePostScoreAction::class)->handle($post);
            });
    }
}
