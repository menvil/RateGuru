<?php

namespace Database\Seeders;

use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\CommentStatus;
use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Comment;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoFillSeeder extends Seeder
{
    private const USER_COUNT  = 100;
    private const POST_COUNT  = 20;
    private const VOTE_RATIO  = 0.80; // 80% of users vote per post

    private const POST_TITLES = [
        'Golden Gate at Sunrise',
        'Mountain Trail in Autumn',
        'City Lights Reflection',
        'Tropical Beach Horizon',
        'Snowy Pine Forest',
        'Desert Sand Dunes',
        'Waterfall in Jungle',
        'Old Town Alleyway',
        'Night Sky Over Sea',
        'Lavender Field at Dusk',
        'Rustic Barn in Meadow',
        'Icy Lake Reflections',
        'Volcanic Crater View',
        'Autumn River Bend',
        'Misty Valley Morning',
        'Coral Reef Dive',
        'Savanna Sunset',
        'Cherry Blossom Walk',
        'Canyon Edge Vista',
        'Lighthouse Storm',
    ];

    private const COMMENT_BODIES = [
        'Really interesting take on this!',
        'I completely agree with the rating here.',
        'Not sure about this one, seems off to me.',
        'Lovely shot, the composition is great.',
        'This one stands out among the rest.',
        'Impressive quality overall.',
        'Feels a bit average to be honest.',
        'Could be better in several ways.',
        'Absolutely stunning, love every detail.',
        'This deserves way more attention.',
        'A bit overrated if you ask me.',
        'The colors really pop in this one.',
        'Solid choice, five stars from me.',
        'Nothing special but still decent.',
        'Would definitely recommend this.',
        'Hard to disagree with the consensus here.',
        'Surprised this is not ranked higher.',
        'Looks much better in person apparently.',
        'Classic style, timeless appeal.',
        'The details make all the difference.',
    ];

    private const REPLY_BODIES = [
        'Good point, I was thinking the same.',
        'Totally agree with you on this.',
        'Interesting perspective!',
        'That is a fair observation.',
        'I had the same reaction initially.',
        'Thanks for sharing your thoughts.',
        'Not sure I see it that way, but okay.',
        'You raise a valid concern.',
        'Hard to argue with that reasoning.',
        'Exactly what I was going to say.',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('DemoFillSeeder only runs in local/testing environment.');

            return;
        }

        $this->command->info('Creating '.self::USER_COUNT.' users...');
        $users = $this->createUsers();

        $this->command->info('Creating '.self::POST_COUNT.' posts with images...');
        $posts = $this->createPosts($users);

        $this->command->info('Seeding votes (80% participation)...');
        $this->seedVotes($users, $posts);

        $this->command->info('Seeding comments (2-3 levels deep)...');
        $this->seedComments($users, $posts);

        $this->command->info('Recalculating post counters and scores...');
        $this->recalculatePosts($posts);

        $this->command->info('DemoFillSeeder done.');
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function createUsers(): \Illuminate\Support\Collection
    {
        $created = collect();

        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $name     = fake()->name();
            $username = 'user_fill_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $email    = "fill{$i}@demo.test";

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name'               => $name,
                    'username'           => $username,
                    'password'           => Hash::make('password'),
                    'email_verified_at'  => now(),
                    'role'               => UserRole::User,
                    'status'             => UserStatus::Active,
                    'trust_level'        => 1,
                ],
            );

            $created->push($user);
        }

        return $created;
    }

    /** @return \Illuminate\Support\Collection<int, Post> */
    private function createPosts(\Illuminate\Support\Collection $users): \Illuminate\Support\Collection
    {
        $created    = collect();
        $originTypes  = OriginType::cases();
        $cuisineTypes = CuisineType::cases();
        $shuffledUsers = $users->shuffle();
        $baseTime = CarbonImmutable::now()->subDays(30);

        foreach (self::POST_TITLES as $index => $title) {
            $author    = $shuffledUsers[$index % $shuffledUsers->count()];
            $imagePath = $this->generatePostImage($author->id, $index + 1);

            $post = Post::query()->updateOrCreate(
                ['title' => $title],
                [
                    'user_id'          => $author->id,
                    'description'      => fake()->paragraph(3),
                    'image_path'       => $imagePath,
                    'image_url'        => null,
                    'thumbnail_url'    => null,
                    'source_url'       => null,
                    'status'           => PostStatus::Published,
                    'origin_truth'     => $originTypes[array_rand($originTypes)],
                    'cuisine_truth'    => $cuisineTypes[array_rand($cuisineTypes)],
                    'published_at'     => $baseTime->addHours($index * 12),
                    'upvotes_count'    => 0,
                    'downvotes_count'  => 0,
                    'comments_count'   => 0,
                    'hot_score'        => 0,
                ],
            );

            $created->push($post);
        }

        return $created;
    }

    private function generatePostImage(int $userId, int $index): string
    {
        // Unique color palette per image index
        $palettes = [
            [0x4F46E5, 0x7C3AED], [0x059669, 0x10B981], [0xDC2626, 0xF59E0B],
            [0x0EA5E9, 0x6366F1], [0xF97316, 0xEF4444], [0x8B5CF6, 0xEC4899],
            [0x14B8A6, 0x3B82F6], [0xF59E0B, 0x22C55E], [0xEF4444, 0x8B5CF6],
            [0x06B6D4, 0xF97316], [0x84CC16, 0x06B6D4], [0xE11D48, 0x7C3AED],
            [0xF59E0B, 0x0EA5E9], [0x10B981, 0xF97316], [0x6366F1, 0x14B8A6],
            [0xDC2626, 0x059669], [0x0EA5E9, 0x84CC16], [0x8B5CF6, 0x22C55E],
            [0xEC4899, 0x06B6D4], [0xF97316, 0x6366F1],
        ];

        $palette = $palettes[($index - 1) % count($palettes)];
        $color1  = $palette[0];
        $color2  = $palette[1];

        $w = 800;
        $h = 600;

        $im = imagecreatetruecolor($w, $h);

        $r1 = ($color1 >> 16) & 0xFF;
        $g1 = ($color1 >> 8) & 0xFF;
        $b1 = $color1 & 0xFF;
        $r2 = ($color2 >> 16) & 0xFF;
        $g2 = ($color2 >> 8) & 0xFF;
        $b2 = $color2 & 0xFF;

        // Diagonal gradient
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $ratio = ($x / $w + $y / $h) / 2;
                $r = (int) ($r1 + ($r2 - $r1) * $ratio);
                $g = (int) ($g1 + ($g2 - $g1) * $ratio);
                $b = (int) ($b1 + ($b2 - $b1) * $ratio);
                imagesetpixel($im, $x, $y, imagecolorallocate($im, $r, $g, $b));
            }
        }

        // Overlay a white semi-transparent circle (for visual interest)
        $cx = (int) ($w * 0.65);
        $cy = (int) ($h * 0.35);
        for ($r = 160; $r > 0; $r -= 2) {
            $alpha = (int) (100 - $r * 0.5);
            $alpha = max(0, min(127, $alpha));
            $c = imagecolorallocatealpha($im, 255, 255, 255, $alpha);
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $c);
        }

        $dir = storage_path("app/public/posts/{$userId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename   = 'fill_post_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.jpg';
        $fullPath   = "{$dir}/{$filename}";
        imagejpeg($im, $fullPath, 85);
        imagedestroy($im);

        return "posts/{$userId}/{$filename}";
    }

    private function seedVotes(
        \Illuminate\Support\Collection $users,
        \Illuminate\Support\Collection $posts,
    ): void {
        $ratingGroup   = RatingGroup::query()->first();
        $ratingOptions = $ratingGroup?->options ?? collect();
        $originTypes   = [OriginType::Homemade, OriginType::Restaurant];
        $cuisineTypes  = CuisineType::cases();

        foreach ($posts as $post) {
            $voters = $users
                ->where('id', '!=', $post->user_id)
                ->shuffle()
                ->take((int) round($users->count() * self::VOTE_RATIO));

            foreach ($voters as $user) {
                // Up/down vote (70% up, 30% down)
                $voteType = fake()->boolean(70) ? VoteType::Up : VoteType::Down;
                PostVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['type' => $voteType],
                );

                // Source vote
                OriginVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['origin' => $originTypes[array_rand($originTypes)]],
                );

                // Category vote
                CuisineVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['cuisine' => $cuisineTypes[array_rand($cuisineTypes)]],
                );

                // Rating vote
                if ($ratingOptions->isNotEmpty()) {
                    $option = $ratingOptions->random();
                    $exists = DB::table('rating_votes')
                        ->where('post_id', $post->id)
                        ->where('user_id', $user->id)
                        ->where('rating_group_id', $ratingGroup->id)
                        ->exists();

                    if (! $exists) {
                        DB::table('rating_votes')->insert([
                            'post_id'          => $post->id,
                            'user_id'          => $user->id,
                            'rating_group_id'  => $ratingGroup->id,
                            'rating_option_id' => $option->id,
                            'created_at'       => now(),
                            'updated_at'       => now(),
                        ]);
                    }
                }
            }
        }
    }

    private function seedComments(
        \Illuminate\Support\Collection $users,
        \Illuminate\Support\Collection $posts,
    ): void {
        foreach ($posts as $post) {
            $commenters = $users
                ->where('id', '!=', $post->user_id)
                ->shuffle()
                ->take(fake()->numberBetween(4, 8));

            $topLevelComments = collect();

            // Level 1 — top-level comments
            foreach ($commenters as $user) {
                $comment = Comment::query()->create([
                    'post_id'   => $post->id,
                    'user_id'   => $user->id,
                    'parent_id' => null,
                    'body'      => $this->randomBody(self::COMMENT_BODIES),
                    'status'    => CommentStatus::Visible,
                ]);

                $topLevelComments->push($comment);
            }

            // Level 2 — replies to top-level (2-4 per comment)
            $level2Comments = collect();
            foreach ($topLevelComments as $parent) {
                $replyCount = fake()->numberBetween(1, 4);
                $replyUsers = $users
                    ->where('id', '!=', $post->user_id)
                    ->shuffle()
                    ->take($replyCount);

                foreach ($replyUsers as $user) {
                    $reply = Comment::query()->create([
                        'post_id'   => $post->id,
                        'user_id'   => $user->id,
                        'parent_id' => $parent->id,
                        'body'      => $this->randomBody(self::REPLY_BODIES),
                        'status'    => CommentStatus::Visible,
                    ]);

                    $level2Comments->push($reply);
                }
            }

            // Level 3 — replies to some level-2 comments (not all)
            foreach ($level2Comments->random(min($level2Comments->count(), fake()->numberBetween(2, 5))) as $parent) {
                $replyUsers = $users
                    ->where('id', '!=', $post->user_id)
                    ->shuffle()
                    ->take(fake()->numberBetween(1, 2));

                foreach ($replyUsers as $user) {
                    Comment::query()->create([
                        'post_id'   => $post->id,
                        'user_id'   => $user->id,
                        'parent_id' => $parent->id,
                        'body'      => $this->randomBody(self::REPLY_BODIES),
                        'status'    => CommentStatus::Visible,
                    ]);
                }
            }

            // Update comments_count on post
            $count = Comment::query()
                ->where('post_id', $post->id)
                ->where('status', CommentStatus::Visible)
                ->count();

            $post->update(['comments_count' => $count]);
        }
    }

    private function recalculatePosts(\Illuminate\Support\Collection $posts): void
    {
        $recalculateCounters = app(RecalculatePostCountersAction::class);
        $recalculateScore    = app(RecalculatePostScoreAction::class);

        foreach ($posts as $post) {
            $post->refresh();
            $recalculateCounters->handle($post);
            $post->refresh();
            $recalculateScore->handle($post);
        }
    }

    private function randomBody(array $pool): string
    {
        return $pool[array_rand($pool)];
    }
}
