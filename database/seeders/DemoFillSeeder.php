<?php

namespace Database\Seeders;

use App\Actions\Counters\RecalculateCommentCountersAction;
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
use App\Models\CommentVote;
use App\Models\CuisineVote;
use App\Models\OriginVote;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoFillSeeder extends Seeder
{
    private const USER_COUNT       = 100;
    private const POST_COUNT       = 20;
    private const VOTE_RATIO       = 0.80;   // 80% of users vote per post
    private const COMMENT_VOTE_RATIO = 0.40; // 40% of users vote per comment

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

    // 20 two-color palettes (hex)
    private const PALETTES = [
        [0x4F46E5, 0x7C3AED], [0x059669, 0x10B981], [0xDC2626, 0xF59E0B],
        [0x0EA5E9, 0x6366F1], [0xF97316, 0xEF4444], [0x8B5CF6, 0xEC4899],
        [0x14B8A6, 0x3B82F6], [0xF59E0B, 0x22C55E], [0xEF4444, 0x8B5CF6],
        [0x06B6D4, 0xF97316], [0x84CC16, 0x06B6D4], [0xE11D48, 0x7C3AED],
        [0xF59E0B, 0x0EA5E9], [0x10B981, 0xF97316], [0x6366F1, 0x14B8A6],
        [0xDC2626, 0x059669], [0x0EA5E9, 0x84CC16], [0x8B5CF6, 0x22C55E],
        [0xEC4899, 0x06B6D4], [0xF97316, 0x6366F1],
    ];

    // 5 visual styles cycling per image
    private const STYLE_DIAGONAL  = 0;
    private const STYLE_RADIAL    = 1;
    private const STYLE_STRIPES   = 2;
    private const STYLE_GRID      = 3;
    private const STYLE_WAVES     = 4;

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

        $this->command->info('Seeding post votes (80% participation)...');
        $this->seedPostVotes($users, $posts);

        $this->command->info('Seeding comments (2-3 levels deep)...');
        $comments = $this->seedComments($users, $posts);

        $this->command->info('Seeding comment votes (40% participation)...');
        $this->seedCommentVotes($users, $comments);

        $this->command->info('Recalculating post counters and scores...');
        $this->recalculatePosts($posts);

        $this->command->info('DemoFillSeeder done.');
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, User> */
    private function createUsers(): \Illuminate\Support\Collection
    {
        $created = collect();

        for ($i = 1; $i <= self::USER_COUNT; $i++) {
            $user = User::query()->firstOrCreate(
                ['email' => "fill{$i}@demo.test"],
                [
                    'name'              => fake()->name(),
                    'username'          => 'user_fill_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'password'          => Hash::make('password'),
                    'email_verified_at' => now(),
                    'role'              => UserRole::User,
                    'status'            => UserStatus::Active,
                    'trust_level'       => 1,
                ],
            );

            $created->push($user);
        }

        return $created;
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, Post> */
    private function createPosts(\Illuminate\Support\Collection $users): \Illuminate\Support\Collection
    {
        $created      = collect();
        $originTypes  = OriginType::cases();
        $cuisineTypes = CuisineType::cases();
        $shuffled     = $users->shuffle();
        $baseTime     = CarbonImmutable::now()->subDays(30);

        foreach (self::POST_TITLES as $index => $title) {
            $author    = $shuffled[$index % $shuffled->count()];
            $imagePath = $this->generatePostImage($author->id, $index + 1);

            $post = Post::query()->updateOrCreate(
                ['title' => $title],
                [
                    'user_id'         => $author->id,
                    'description'     => fake()->paragraph(3),
                    'image_path'      => $imagePath,
                    'image_url'       => null,
                    'thumbnail_url'   => null,
                    'source_url'      => null,
                    'status'          => PostStatus::Published,
                    'origin_truth'    => $originTypes[array_rand($originTypes)],
                    'cuisine_truth'   => $cuisineTypes[array_rand($cuisineTypes)],
                    'published_at'    => $baseTime->addHours($index * 12),
                    'upvotes_count'   => 0,
                    'downvotes_count' => 0,
                    'comments_count'  => 0,
                    'hot_score'       => 0,
                ],
            );

            $created->push($post);
        }

        return $created;
    }

    // -------------------------------------------------------------------------
    // Image generation (5 visual styles, 20 color palettes)
    // -------------------------------------------------------------------------

    private function generatePostImage(int $userId, int $index): string
    {
        $palette = self::PALETTES[($index - 1) % count(self::PALETTES)];
        $style   = ($index - 1) % 5;

        [$r1, $g1, $b1] = $this->hexToRgb($palette[0]);
        [$r2, $g2, $b2] = $this->hexToRgb($palette[1]);

        $w  = 800;
        $h  = 600;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);

        match ($style) {
            self::STYLE_DIAGONAL => $this->drawDiagonalGradient($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            self::STYLE_RADIAL   => $this->drawRadialGradient($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            self::STYLE_STRIPES  => $this->drawStripes($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            self::STYLE_GRID     => $this->drawGrid($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            self::STYLE_WAVES    => $this->drawWaves($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
        };

        // Subtle dark vignette around the edges
        $this->drawVignette($im, $w, $h);

        $dir = storage_path("app/public/posts/{$userId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'fill_post_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.jpg';
        $fullPath = "{$dir}/{$filename}";
        imagejpeg($im, $fullPath, 88);
        imagedestroy($im);

        return "posts/{$userId}/{$filename}";
    }

    /** @return array{int,int,int} */
    private function hexToRgb(int $hex): array
    {
        return [($hex >> 16) & 0xFF, ($hex >> 8) & 0xFF, $hex & 0xFF];
    }

    private function lerp(int $a, int $b, float $t): int
    {
        return (int) ($a + ($b - $a) * $t);
    }

    /** Style 0: smooth diagonal gradient */
    private function drawDiagonalGradient(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $t = ($x / $w + $y / $h) / 2;
                $c = imagecolorallocate($im, $this->lerp($r1, $r2, $t), $this->lerp($g1, $g2, $t), $this->lerp($b1, $b2, $t));
                imagesetpixel($im, $x, $y, $c);
            }
        }

        // White glow circle top-right
        $cx = (int) ($w * 0.72);
        $cy = (int) ($h * 0.28);
        for ($r = 140; $r > 0; $r -= 2) {
            $alpha = max(0, min(127, (int) (127 - $r * 0.6)));
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    /** Style 1: radial gradient (light center, dark edges) */
    private function drawRadialGradient(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $cx   = $w / 2;
        $cy   = $h / 2;
        $maxD = sqrt($cx * $cx + $cy * $cy);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $t = min(1.0, sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxD);
                $c = imagecolorallocate($im, $this->lerp($r1, $r2, $t), $this->lerp($g1, $g2, $t), $this->lerp($b1, $b2, $t));
                imagesetpixel($im, $x, $y, $c);
            }
        }

        // Small bright center spot
        for ($r = 60; $r > 0; $r -= 2) {
            $alpha = max(0, min(127, (int) (127 - $r * 1.5)));
            imagefilledellipse($im, (int) $cx, (int) $cy, $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    /** Style 2: bold horizontal stripes */
    private function drawStripes(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $stripeCount = 8;
        $stripeH     = (int) ceil($h / $stripeCount);

        // Background: color1
        imagefill($im, 0, 0, imagecolorallocate($im, $r1, $g1, $b1));

        for ($s = 0; $s < $stripeCount; $s++) {
            $t  = $s / ($stripeCount - 1);
            $y0 = $s * $stripeH;

            if ($s % 2 === 0) {
                // Solid stripe: blend between c1 and c2
                $c = imagecolorallocate($im, $this->lerp($r1, $r2, $t), $this->lerp($g1, $g2, $t), $this->lerp($b1, $b2, $t));
                imagefilledrectangle($im, 0, $y0, $w - 1, $y0 + $stripeH - 1, $c);
            } else {
                // Thin accent stripe
                $c = imagecolorallocate($im, min(255, $this->lerp($r2, $r1, $t) + 40), min(255, $this->lerp($g2, $g1, $t) + 40), min(255, $this->lerp($b2, $b1, $t) + 40));
                imagefilledrectangle($im, 0, $y0, $w - 1, $y0 + (int) ($stripeH * 0.15), $c);
            }
        }

        // Diagonal highlight bar
        for ($x = 0; $x < $w + $h; $x += 2) {
            $alpha = max(0, min(127, (int) (110 - abs($x - $w * 0.6) * 0.4)));
            $line  = imagecolorallocatealpha($im, 255, 255, 255, $alpha);
            imageline($im, $x, 0, $x - $h, $h, $line);
        }
    }

    /** Style 3: colorful grid mosaic */
    private function drawGrid(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $cols    = 6;
        $rows    = 5;
        $cellW   = (int) ceil($w / $cols);
        $cellH   = (int) ceil($h / $rows);

        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $t  = ($row * $cols + $col) / ($rows * $cols - 1);
                // Alternate between c1→c2 and c2→c1 in a checkerboard pattern
                $flip = ($row + $col) % 2 === 0;
                $rt   = $flip ? $t : 1 - $t;
                $c    = imagecolorallocate($im, $this->lerp($r1, $r2, $rt), $this->lerp($g1, $g2, $rt), $this->lerp($b1, $b2, $rt));

                $x0 = $col * $cellW;
                $y0 = $row * $cellH;
                imagefilledrectangle($im, $x0, $y0, $x0 + $cellW - 1, $y0 + $cellH - 1, $c);

                // 1px dark gap
                $gap = imagecolorallocatealpha($im, 0, 0, 0, 80);
                imagerectangle($im, $x0, $y0, $x0 + $cellW - 1, $y0 + $cellH - 1, $gap);
            }
        }

        // Center overlay circle
        $cx = $w / 2;
        $cy = $h / 2;
        for ($r = 100; $r > 0; $r -= 3) {
            $alpha = max(0, min(127, (int) (127 - $r)));
            imagefilledellipse($im, (int) $cx, (int) $cy, $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    /** Style 4: sine-wave bands */
    private function drawWaves(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        // Solid background: color1
        imagefill($im, 0, 0, imagecolorallocate($im, $r1, $g1, $b1));

        $waveCount = 6;
        $amplitude = 40;

        for ($waveIdx = 0; $waveIdx < $waveCount; $waveIdx++) {
            $t        = $waveIdx / ($waveCount - 1);
            $yCenter  = (int) ($h * ($waveIdx + 1) / ($waveCount + 1));
            $c        = imagecolorallocatealpha(
                $im,
                $this->lerp($r2, $r1, $t),
                $this->lerp($g2, $g1, $t),
                $this->lerp($b2, $b1, $t),
                (int) (30 + $waveIdx * 10),
            );

            // Draw filled wave band as polygon
            $points = [];
            for ($x = 0; $x <= $w; $x += 4) {
                $yOff     = (int) ($amplitude * sin(($x / $w) * M_PI * 4 + $waveIdx * 0.8));
                $points[] = $x;
                $points[] = $yCenter + $yOff;
            }
            // Close at bottom
            $points[] = $w;
            $points[] = $h;
            $points[] = 0;
            $points[] = $h;

            imagefilledpolygon($im, $points, $c);
        }

        // White shimmer line across center
        for ($x = 0; $x < $w; $x++) {
            $yOff  = (int) (20 * sin($x / $w * M_PI * 3));
            $yLine = (int) ($h / 2) + $yOff;
            $alpha = max(0, min(127, (int) (80 + 40 * sin($x / $w * M_PI * 6))));
            imagesetpixel($im, $x, $yLine, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
            imagesetpixel($im, $x, $yLine + 1, imagecolorallocatealpha($im, 255, 255, 255, $alpha + 20 > 127 ? 127 : $alpha + 20));
        }
    }

    private function drawVignette(\GdImage $im, int $w, int $h): void
    {
        $cx   = $w / 2;
        $cy   = $h / 2;
        $maxD = sqrt($cx * $cx + $cy * $cy);

        // Only draw outer ~30% as vignette for performance
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxD;
                if ($d < 0.65) {
                    continue;
                }
                $strength = ($d - 0.65) / 0.35;
                $alpha    = max(0, min(127, (int) (127 - $strength * 90)));
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, 0, 0, 0, $alpha));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Post votes
    // -------------------------------------------------------------------------

    private function seedPostVotes(
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
                $voteType = fake()->boolean(70) ? VoteType::Up : VoteType::Down;
                PostVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['type' => $voteType],
                );

                OriginVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['origin' => $originTypes[array_rand($originTypes)]],
                );

                CuisineVote::query()->firstOrCreate(
                    ['post_id' => $post->id, 'user_id' => $user->id],
                    ['cuisine' => $cuisineTypes[array_rand($cuisineTypes)]],
                );

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

    // -------------------------------------------------------------------------
    // Comments (3 levels)
    // -------------------------------------------------------------------------

    /** @return \Illuminate\Support\Collection<int, Comment> */
    private function seedComments(
        \Illuminate\Support\Collection $users,
        \Illuminate\Support\Collection $posts,
    ): \Illuminate\Support\Collection {
        $allComments = collect();

        foreach ($posts as $post) {
            $commenters = $users
                ->where('id', '!=', $post->user_id)
                ->shuffle()
                ->take(fake()->numberBetween(4, 8));

            $topLevel = collect();

            foreach ($commenters as $user) {
                $c = Comment::query()->create([
                    'post_id'   => $post->id,
                    'user_id'   => $user->id,
                    'parent_id' => null,
                    'body'      => $this->randomBody(self::COMMENT_BODIES),
                    'status'    => CommentStatus::Visible,
                ]);
                $topLevel->push($c);
                $allComments->push($c);
            }

            $level2 = collect();
            foreach ($topLevel as $parent) {
                $replyUsers = $users->where('id', '!=', $post->user_id)->shuffle()->take(fake()->numberBetween(1, 4));
                foreach ($replyUsers as $user) {
                    $c = Comment::query()->create([
                        'post_id'   => $post->id,
                        'user_id'   => $user->id,
                        'parent_id' => $parent->id,
                        'body'      => $this->randomBody(self::REPLY_BODIES),
                        'status'    => CommentStatus::Visible,
                    ]);
                    $level2->push($c);
                    $allComments->push($c);
                }
            }

            $deepCount = min($level2->count(), fake()->numberBetween(2, 5));
            foreach ($level2->random($deepCount) as $parent) {
                $replyUsers = $users->where('id', '!=', $post->user_id)->shuffle()->take(fake()->numberBetween(1, 2));
                foreach ($replyUsers as $user) {
                    $c = Comment::query()->create([
                        'post_id'   => $post->id,
                        'user_id'   => $user->id,
                        'parent_id' => $parent->id,
                        'body'      => $this->randomBody(self::REPLY_BODIES),
                        'status'    => CommentStatus::Visible,
                    ]);
                    $allComments->push($c);
                }
            }

            $count = Comment::query()
                ->where('post_id', $post->id)
                ->where('status', CommentStatus::Visible)
                ->count();

            $post->update(['comments_count' => $count]);
        }

        return $allComments;
    }

    // -------------------------------------------------------------------------
    // Comment votes
    // -------------------------------------------------------------------------

    private function seedCommentVotes(
        \Illuminate\Support\Collection $users,
        \Illuminate\Support\Collection $comments,
    ): void {
        $recalculate = app(RecalculateCommentCountersAction::class);

        foreach ($comments as $comment) {
            $voters = $users
                ->where('id', '!=', $comment->user_id)
                ->shuffle()
                ->take((int) round($users->count() * self::COMMENT_VOTE_RATIO));

            foreach ($voters as $user) {
                $type = fake()->boolean(75) ? VoteType::Up : VoteType::Down;
                CommentVote::query()->firstOrCreate(
                    ['comment_id' => $comment->id, 'user_id' => $user->id],
                    ['type' => $type],
                );
            }

            $recalculate->handle($comment);
        }
    }

    // -------------------------------------------------------------------------
    // Post counter recalculation
    // -------------------------------------------------------------------------

    private function recalculatePosts(\Illuminate\Support\Collection $posts): void
    {
        $counters = app(RecalculatePostCountersAction::class);
        $score    = app(RecalculatePostScoreAction::class);

        foreach ($posts as $post) {
            $post->refresh();
            $counters->handle($post);
            $post->refresh();
            $score->handle($post);
        }
    }

    private function randomBody(array $pool): string
    {
        return $pool[array_rand($pool)];
    }
}
