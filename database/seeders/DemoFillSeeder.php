<?php

namespace Database\Seeders;

use App\Actions\Counters\RecalculateCommentCountersAction;
use App\Actions\Counters\RecalculatePostCountersAction;
use App\Actions\Ranking\RecalculatePostScoreAction;
use App\Enums\CommentStatus;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VoteType;
use App\Models\Category;
use App\Models\Comment;
use App\Models\CommentVote;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostVote;
use App\Models\RatingGroup;
use App\Models\RatingVote;
use App\Models\User;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaVariantGenerator;
use App\Support\Media\ImageOrientationClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DemoFillSeeder extends Seeder
{
    private const USER_COUNT = 500;

    private const IMAGE_WIDTH = 800;

    private const IMAGE_HEIGHT = 600;

    private const VOTE_RATIO = 0.85;  // 85% of users vote per post

    private const COMMENT_VOTE_RATIO = 0.50;  // 50% of users vote per comment

    private const POST_TITLES = [
        // Nature / Landscape
        'Golden Gate at Sunrise', 'Mountain Trail in Autumn', 'Tropical Beach Horizon',
        'Snowy Pine Forest', 'Desert Sand Dunes', 'Waterfall in Jungle',
        'Night Sky Over Sea', 'Lavender Field at Dusk', 'Icy Lake Reflections',
        'Volcanic Crater View', 'Autumn River Bend', 'Misty Valley Morning',
        'Coral Reef Dive', 'Savanna Sunset', 'Cherry Blossom Walk',
        'Canyon Edge Vista', 'Lighthouse Storm', 'Glacier Blue Water',
        'Rainforest Canopy View', 'Arctic Northern Lights',
        // City / Architecture
        'City Lights Reflection', 'Old Town Alleyway', 'Rooftop Garden Terrace',
        'Underground Metro Station', 'Neon District Night', 'Harbor Bridge at Dusk',
        'Ancient Stone Temple', 'Glass Tower Reflection', 'Cobblestone Market Street',
        'Chinatown Lantern Festival', 'Abandoned Factory Interior', 'Grand Library Hall',
        'Steel Suspension Bridge', 'Skyscraper Window View', 'Train Station Vault',
        'Baroque Cathedral Nave', 'Modern Art Museum Atrium', 'Night Market Crowd',
        'Floating Village River', 'Foggy Hilltop Town',
        // Objects / Still Life
        'Rustic Barn in Meadow', 'Farm Table Harvest', 'Wood Fire Pizza',
        'Artisan Bread Loaves', 'Morning Coffee Pour', 'Colorful Spice Market',
        'Sushi Platter Display', 'Fresh Pasta Dough', 'Street Taco Stand',
        'Fruit Bowl Overhead', 'Dark Chocolate Temper', 'Wine Cellar Barrels',
        'Oyster Bar Selection', 'Garden Herb Collection', 'Sourdough Cross Section',
        'Smoked Salmon Platter', 'Ice Cream Scoop Stack', 'Truffle Hunt Forest',
        'Cheese Cave Aging', 'Fermentation Jars Row',
        // Abstract / Texture
        'Geometric Shadow Play', 'Rust Texture Closeup', 'Sand Pattern Aerial',
        'Water Ripple Abstract', 'Cracked Earth Surface', 'Ink Drop Spread',
        'Soap Bubble Macro', 'Crystal Refraction Light', 'Bark Texture Detail',
        'Feather Pattern Closeup', 'Fabric Weave Macro', 'Metal Mesh Surface',
        'Frost Pattern Window', 'Oil Slick Rainbow', 'Smoke Curl Backlit',
        'Paper Fold Origami', 'Concrete Stain Art', 'Circuit Board Aerial',
        'Paint Pour Result', 'Kaleidoscope Mirror',
        // People / Lifestyle
        'Surfer Sunrise Session', 'Climber Summit Push', 'Cyclist Mountain Descent',
        'Yoga Cliff Edge', 'Kayak Cave Entrance', 'Runner City Bridge',
        'Skater Empty Bowl', 'Diver Coral Garden', 'Hiker Ridge Walk',
        'Paddleboard Lake Mirror', 'Dancer Motion Blur', 'Street Musician Corner',
        'Portrait Window Light', 'Craftsman Workshop', 'Fisherman Golden Hour',
        'Child Tide Pool', 'Elder Garden Morning', 'Couple Rainforest Walk',
        'Group Summit Celebration', 'Solo Desert Traveler',
    ];

    private const COMMENT_BODIES = [
        'Really interesting take on this!',
        'I completely agree with the rating here.',
        'Not sure about this one, seems a bit off.',
        'Lovely composition, the details are great.',
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
        'Genuinely one of the best I have seen.',
        'Not what I expected but pleasantly surprised.',
        'The composition is really well thought out.',
        'Could use a bit more contrast here.',
        'Beautiful use of natural lighting.',
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
        'Yeah that stood out to me too.',
        'Fair enough, makes sense.',
    ];

    private const PALETTES = [
        [0x4F46E5, 0x7C3AED], [0x059669, 0x10B981], [0xDC2626, 0xF59E0B],
        [0x0EA5E9, 0x6366F1], [0xF97316, 0xEF4444], [0x8B5CF6, 0xEC4899],
        [0x14B8A6, 0x3B82F6], [0xF59E0B, 0x22C55E], [0xEF4444, 0x8B5CF6],
        [0x06B6D4, 0xF97316], [0x84CC16, 0x06B6D4], [0xE11D48, 0x7C3AED],
        [0xF59E0B, 0x0EA5E9], [0x10B981, 0xF97316], [0x6366F1, 0x14B8A6],
        [0xDC2626, 0x059669], [0x0EA5E9, 0x84CC16], [0x8B5CF6, 0x22C55E],
        [0xEC4899, 0x06B6D4], [0xF97316, 0x6366F1], [0x7C3AED, 0x0EA5E9],
        [0x22C55E, 0xEC4899], [0xF59E0B, 0x6366F1], [0xEF4444, 0x14B8A6],
        [0x3B82F6, 0xF97316],
    ];

    public function run(MediaStorage $mediaStorage, MediaVariantGenerator $mediaVariantGenerator): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->warn('DemoFillSeeder only runs in local/testing environment.');

            return;
        }

        $this->command->info('Creating '.$this->userCount().' users...');
        $users = $this->createUsers();

        $this->command->info('Removing previously generated media...');
        $this->clearGeneratedMedia();

        $this->command->info('Creating '.count($this->postTitles()).' posts with images...');
        $posts = $this->createPosts($users, $mediaStorage, $mediaVariantGenerator);

        $this->command->info('Removing previously generated interactions...');
        $this->clearGeneratedInteractions($users, $posts);

        $this->command->info('Seeding post votes ('.$this->percentage($this->voteRatio()).'% participation)...');
        $this->seedPostVotes($users, $posts);

        $this->command->info('Seeding comments (2-3 levels deep)...');
        $comments = $this->seedComments($users, $posts);

        $this->command->info('Seeding comment votes ('.$this->percentage($this->commentVoteRatio()).'% participation)...');
        $this->seedCommentVotes($users, $comments);

        $this->command->info('Recalculating post counters and scores...');
        $this->recalculatePosts($posts);

        $this->command->info('DemoFillSeeder done.');
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    private function createUsers(): Collection
    {
        $rows = [];
        $now = now()->toDateTimeString();
        $password = Hash::make('password');

        for ($i = 1; $i <= $this->userCount(); $i++) {
            $rows[] = [
                'name' => fake()->name(),
                'username' => 'user_fill_'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'email' => "fill{$i}@demo.test",
                'password' => $password,
                'email_verified_at' => $now,
                'role' => UserRole::User->value,
                'status' => UserStatus::Active->value,
                'trust_level' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('users')->upsert($chunk, ['email'], array_keys($chunk[0]));
        }

        return User::query()
            ->where('email', 'like', 'fill%@demo.test')
            ->orderBy('id')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    private function createPosts(Collection $users, MediaStorage $mediaStorage, MediaVariantGenerator $mediaVariantGenerator): Collection
    {
        $titles = $this->postTitles();
        $categoryIds = Category::query()->active()->ordered()->pluck('id')->all();
        $authors = $users->values();
        $baseTime = CarbonImmutable::now()->subDays(60);
        $now = now()->toDateTimeString();

        $disk = (string) config('media.disks.public');

        foreach ($titles as $index => $title) {
            $author = $authors[$index % $authors->count()];

            // This seeder regenerates the same deterministic path on every
            // rerun, so on a failure below the file may belong to an
            // already-seeded, still-referenced post rather than to this
            // attempt. Look up before writing: only a path with no existing
            // media_assets row is "new" and safe to delete on failure —
            // otherwise compensating would break an existing post's image.
            // The id found here is passed into ensurePostImageMediaAsset()
            // below so it doesn't have to repeat the same lookup.
            $imagePath = $this->postImagePath($author->id, $index + 1);
            $existingImageAssetId = MediaAsset::withTrashed()
                ->where(['disk' => $disk, 'path' => $imagePath])
                ->value('id');
            $isNewImage = $existingImageAssetId === null;

            // The image file is written to disk first (slow, external I/O
            // that doesn't belong inside a DB transaction). If the DB work
            // below then fails, a newly-created file is removed as
            // compensation — it isn't covered by the transaction rollback.
            $this->generatePostImage($author->id, $index + 1, $mediaStorage);
            $categoryId = $categoryIds === [] || $index % 3 === 2
                ? null
                : $categoryIds[$index % count($categoryIds)];

            $imageAssetId = null;

            try {
                DB::transaction(function () use ($author, $title, $imagePath, $existingImageAssetId, $categoryId, $baseTime, $index, $now, $mediaStorage, &$imageAssetId): void {
                    $imageAssetId = $this->ensurePostImageMediaAsset($imagePath, $author->id, $existingImageAssetId, $mediaStorage);

                    // Raw query builder (not Eloquent) is intentional here:
                    // this loop runs once per demo post title (up to ~99) on
                    // every re-seed, and updateOrInsert() keeps that bulk
                    // upsert fast without instantiating/hydrating a model per
                    // row.
                    DB::table('posts')->updateOrInsert(
                        ['title' => $title],
                        [
                            'user_id' => $author->id,
                            'description' => fake()->paragraph(3),
                            'image_asset_id' => $imageAssetId,
                            'source_url' => null,
                            'category_id' => $categoryId,
                            'status' => PostStatus::Published->value,
                            // 14 h × 99 posts = 1386 h = 57.75 days — always within the 60-day window
                            'published_at' => $baseTime->addHours($index * 14)->toDateTimeString(),
                            'deleted_at' => null, // clear any previous soft-delete so Eloquent finds the row
                            'upvotes_count' => 0,
                            'downvotes_count' => 0,
                            'comments_count' => 0,
                            'hot_score' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    );
                });
            } catch (Throwable $exception) {
                // Best-effort: a cleanup failure here is reported but must
                // never replace the original database exception being
                // propagated below.
                if ($isNewImage) {
                    try {
                        $mediaStorage->delete(new MediaLocation($disk, $imagePath));
                    } catch (Throwable $cleanupException) {
                        report($cleanupException);
                    }
                }

                throw $exception;
            }

            // Synchronous, not queued: this seeder needs every post's
            // variants to exist by the time it finishes, and QUEUE_CONNECTION
            // is sync locally anyway — dispatching would just add indirection.
            $imageAsset = MediaAsset::find($imageAssetId);

            if ($imageAsset !== null) {
                $mediaVariantGenerator->generateAll($imageAsset);
            }

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        return Post::query()
            ->whereIn('title', $titles)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Image generation (5 visual styles)
    // -------------------------------------------------------------------------

    /**
     * Raw GD, not Illuminate\Support\Facades\Image: procedurally drawing
     * shapes/gradients onto a blank canvas has no equivalent in Laravel's
     * Image component (or in Intervention/Image, which it wraps) — that API
     * only transforms an existing image (resize/crop/rotate/etc). Even the
     * final encode step below can't move to it either, since encoding a raw
     * \GdImage through the facade requires its driver, and
     * intervention/image isn't installed in this project.
     */
    private function generatePostImage(int $userId, int $index, MediaStorage $mediaStorage): string
    {
        $palette = self::PALETTES[($index - 1) % count(self::PALETTES)];
        $style = ($index - 1) % 5;
        [$r1,$g1,$b1] = $this->hexToRgb($palette[0]);
        [$r2,$g2,$b2] = $this->hexToRgb($palette[1]);
        $w = self::IMAGE_WIDTH;
        $h = self::IMAGE_HEIGHT;
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, true);

        match ($style) {
            0 => $this->drawDiagonal($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            1 => $this->drawRadial($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            2 => $this->drawStripes($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            3 => $this->drawGrid($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
            4 => $this->drawWaves($im, $w, $h, $r1, $g1, $b1, $r2, $g2, $b2),
        };

        $this->drawVignette($im, $w, $h);

        $path = $this->postImagePath($userId, $index);
        ob_start();
        $encoded = imagejpeg($im, null, 85);
        $contents = ob_get_clean();
        imagedestroy($im);

        if (! $encoded || ! is_string($contents)) {
            throw new RuntimeException("Unable to create demo fill image at [{$path}].");
        }

        $mediaStorage->putContents(
            new MediaLocation((string) config('media.disks.public'), $path),
            $contents,
            MediaVisibility::Public,
        );

        return $path;
    }

    private function postImagePath(int $userId, int $index): string
    {
        $filename = 'fill_post_'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'.jpg';

        return "posts/{$userId}/{$filename}";
    }

    /**
     * Reuses the media_assets row already at this (disk, path) instead of
     * inserting a new one on every re-seed — otherwise each rerun would leave
     * the previous run's row behind, active but no longer referenced by any
     * post. generatePostImage() always writes an IMAGE_WIDTH x IMAGE_HEIGHT
     * JPEG, so dimensions are fixed rather than re-derived from the file.
     *
     * $existingId is looked up once by the caller (which also needs it to
     * decide whether the file is safe to delete on failure) rather than
     * being re-queried here.
     */
    private function ensurePostImageMediaAsset(string $path, int $userId, ?int $existingId, MediaStorage $mediaStorage): int
    {
        $disk = (string) config('media.disks.public');
        $location = new MediaLocation($disk, $path);

        if ($existingId !== null) {
            // The row may have been previously soft-deleted — the caller's
            // withTrashed() lookup finds it regardless. Restore it
            // unconditionally (a no-op when it wasn't trashed) — otherwise a
            // post would point at a soft-deleted asset that its imageAsset
            // relation excludes, leaving it with no resolvable image. The
            // file was just regenerated above, so refresh
            // byte_size/owner/updated_at too, not only deleted_at —
            // otherwise a restored row can carry stale metadata from
            // whichever run first created it.
            DB::table('media_assets')
                ->where('id', $existingId)
                ->update([
                    'deleted_at' => null,
                    'owner_user_id' => $userId,
                    'byte_size' => $mediaStorage->size($location),
                    'updated_at' => now()->toDateTimeString(),
                ]);

            return $existingId;
        }

        $width = self::IMAGE_WIDTH;
        $height = self::IMAGE_HEIGHT;
        $now = now()->toDateTimeString();

        return DB::table('media_assets')->insertGetId([
            'owner_user_id' => $userId,
            'kind' => MediaKind::PostImage->value,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => basename($path),
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'byte_size' => $mediaStorage->size($location),
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => round($width / $height, 6),
            'orientation' => app(ImageOrientationClassifier::class)->classify($width, $height)->value,
            'status' => MediaStatus::Ready->value,
            'visibility' => MediaVisibility::Public->value,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function hexToRgb(int $hex): array
    {
        return [($hex >> 16) & 0xFF, ($hex >> 8) & 0xFF, $hex & 0xFF];
    }

    private function lerp(int $a, int $b, float $t): int
    {
        return (int) ($a + ($b - $a) * $t);
    }

    // Style 0: column-based diagonal gradient (fast, no per-pixel loop)
    private function drawDiagonal(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        // Approximate diagonal with vertical stripes blended left→right
        for ($x = 0; $x < $w; $x++) {
            $t = $x / ($w - 1);
            $c = imagecolorallocate($im, $this->lerp($r1, $r2, $t), $this->lerp($g1, $g2, $t), $this->lerp($b1, $b2, $t));
            imagefilledrectangle($im, $x, 0, $x, $h - 1, $c);
        }
        // Add top-to-bottom tint for diagonal feel
        for ($y = 0; $y < $h; $y += 2) {
            $alpha = (int) (($y / $h) * 50);
            $tint = imagecolorallocatealpha($im, 0, 0, 0, 127 - $alpha);
            imagefilledrectangle($im, 0, $y, $w - 1, $y + 1, $tint);
        }
        // White glow
        $cx = (int) ($w * 0.72);
        $cy = (int) ($h * 0.28);
        for ($r = 120; $r > 0; $r -= 4) {
            $alpha = max(0, min(127, (int) (127 - $r * 0.7)));
            imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    // Style 1: radial via concentric ellipses (fast)
    private function drawRadial(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        // Fill background with outer color
        imagefill($im, 0, 0, imagecolorallocate($im, $r2, $g2, $b2));
        $steps = 60;
        for ($s = $steps; $s >= 0; $s--) {
            $t = $s / $steps;
            $ew = (int) ($w * $t);
            $eh = (int) ($h * $t);
            $c = imagecolorallocate($im, $this->lerp($r2, $r1, $t), $this->lerp($g2, $g1, $t), $this->lerp($b2, $b1, $t));
            imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), $ew, $eh, $c);
        }
        // Center bright spot
        for ($r = 50; $r > 0; $r -= 3) {
            $alpha = max(0, min(127, (int) (127 - $r * 1.8)));
            imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    // Style 2: bold horizontal stripes with diagonal highlight
    private function drawStripes(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $count = 10;
        $stripeH = (int) ceil($h / $count);
        for ($s = 0; $s < $count; $s++) {
            $t = $s / ($count - 1);
            $y0 = $s * $stripeH;
            $c = imagecolorallocate($im, $this->lerp($r1, $r2, $t), $this->lerp($g1, $g2, $t), $this->lerp($b1, $b2, $t));
            imagefilledrectangle($im, 0, $y0, $w - 1, $y0 + $stripeH - 1, $c);
        }
        // Diagonal shimmer bar
        for ($x = 0; $x < $w + $h; $x += 3) {
            $alpha = max(0, min(127, (int) (100 - abs($x - $w * 0.55) * 0.35)));
            imageline($im, $x, 0, $x - $h, $h, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    // Style 3: grid mosaic
    private function drawGrid(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        $cols = 7;
        $rows = 5;
        $cw = (int) ceil($w / $cols);
        $ch = (int) ceil($h / $rows);
        for ($row = 0; $row < $rows; $row++) {
            for ($col = 0; $col < $cols; $col++) {
                $t = ($row * $cols + $col) / ($rows * $cols - 1);
                $flip = ($row + $col) % 2 === 0;
                $rt = $flip ? $t : 1 - $t;
                $c = imagecolorallocate($im, $this->lerp($r1, $r2, $rt), $this->lerp($g1, $g2, $rt), $this->lerp($b1, $b2, $rt));
                $x0 = $col * $cw;
                $y0 = $row * $ch;
                imagefilledrectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, $c);
                imagerectangle($im, $x0, $y0, $x0 + $cw - 1, $y0 + $ch - 1, imagecolorallocatealpha($im, 0, 0, 0, 80));
            }
        }
        // Center glow
        for ($r = 90; $r > 0; $r -= 4) {
            $alpha = max(0, min(127, (int) (127 - $r)));
            imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), $r * 2, $r * 2, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    // Style 4: wave bands
    private function drawWaves(\GdImage $im, int $w, int $h, int $r1, int $g1, int $b1, int $r2, int $g2, int $b2): void
    {
        imagefill($im, 0, 0, imagecolorallocate($im, $r1, $g1, $b1));
        $waveCount = 7;
        for ($wi = 0; $wi < $waveCount; $wi++) {
            $t = $wi / ($waveCount - 1);
            $yCenter = (int) ($h * ($wi + 1) / ($waveCount + 1));
            $c = imagecolorallocatealpha($im, $this->lerp($r2, $r1, $t), $this->lerp($g2, $g1, $t), $this->lerp($b2, $b1, $t), 20 + $wi * 12);
            $pts = [];
            for ($x = 0; $x <= $w; $x += 5) {
                $pts[] = $x;
                $pts[] = $yCenter + (int) (38 * sin(($x / $w) * M_PI * 4 + $wi * 0.9));
            }
            $pts[] = $w;
            $pts[] = $h;
            $pts[] = 0;
            $pts[] = $h;
            imagefilledpolygon($im, $pts, $c);
        }
        // Shimmer line
        for ($x = 0; $x < $w; $x++) {
            $yLine = (int) ($h / 2 + 18 * sin($x / $w * M_PI * 3));
            $alpha = max(0, min(127, (int) (75 + 35 * sin($x / $w * M_PI * 6))));
            imagesetpixel($im, $x, $yLine, imagecolorallocatealpha($im, 255, 255, 255, $alpha));
        }
    }

    private function drawVignette(\GdImage $im, int $w, int $h): void
    {
        $cx = $w / 2;
        $cy = $h / 2;
        $maxD = sqrt($cx * $cx + $cy * $cy);
        // Sample every 2px for speed
        for ($y = 0; $y < $h; $y += 2) {
            for ($x = 0; $x < $w; $x += 2) {
                $d = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2) / $maxD;
                if ($d < 0.60) {
                    continue;
                }
                $strength = ($d - 0.60) / 0.40;
                $alpha = max(0, min(127, (int) (127 - $strength * 100)));
                $c = imagecolorallocatealpha($im, 0, 0, 0, $alpha);
                imagesetpixel($im, $x, $y, $c);
                if ($x + 1 < $w) {
                    imagesetpixel($im, $x + 1, $y, $c);
                }
                if ($y + 1 < $h) {
                    imagesetpixel($im, $x, $y + 1, $c);
                    if ($x + 1 < $w) {
                        imagesetpixel($im, $x + 1, $y + 1, $c);
                    }
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // Post votes (bulk insert)
    // -------------------------------------------------------------------------

    private function seedPostVotes(
        Collection $users,
        Collection $posts,
    ): void {
        // Load all active rating groups with their active options only
        $ratingGroups = RatingGroup::query()
            ->where('is_active', true)
            ->with(['options' => fn ($q) => $q->where('is_active', true)->whereNull('archived_at')])
            ->get()
            ->filter(fn ($g) => $g->options->isNotEmpty());

        // Build a lookup: groupId → [optionId, ...]  (active options only)
        $groupOptionMap = $ratingGroups->mapWithKeys(fn ($g) => [
            $g->id => $g->options->pluck('id')->all(),
        ])->all();

        $now = now()->toDateTimeString();

        $postVotes = [];
        $ratingVotes = [];

        foreach ($posts as $post) {
            $voters = $users
                ->where('id', '!=', $post->user_id)
                ->shuffle()
                ->take((int) round($users->count() * $this->voteRatio()));

            foreach ($voters as $user) {
                $postVotes[] = [
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'type' => fake()->boolean(70) ? VoteType::Up->value : VoteType::Down->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Vote on every active rating group
                foreach ($groupOptionMap as $groupId => $optionIds) {
                    if ($optionIds === []) {
                        continue;
                    }
                    $ratingVotes[] = [
                        'post_id' => $post->id,
                        'user_id' => $user->id,
                        'rating_group_id' => $groupId,
                        'rating_option_id' => $optionIds[array_rand($optionIds)],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        foreach (array_chunk($postVotes, 500) as $chunk) {
            DB::table('post_votes')->upsert($chunk, ['post_id', 'user_id'], ['type', 'updated_at']);
        }

        foreach (array_chunk($ratingVotes, 500) as $chunk) {
            DB::table('rating_votes')->upsert($chunk, ['post_id', 'user_id', 'rating_group_id'], ['rating_option_id', 'updated_at']);
        }
    }

    // -------------------------------------------------------------------------
    // Comments (3 levels)
    // -------------------------------------------------------------------------

    private function seedComments(
        Collection $users,
        Collection $posts,
    ): Collection {
        $allComments = collect();
        $now = now()->toDateTimeString();

        foreach ($posts as $post) {
            $commenters = $users
                ->where('id', '!=', $post->user_id)
                ->shuffle()
                ->take($this->topLevelCommentCount());

            $topLevel = collect();
            foreach ($commenters as $user) {
                $id = DB::table('comments')->insertGetId([
                    'post_id' => $post->id,
                    'user_id' => $user->id,
                    'parent_id' => null,
                    'body' => $this->randomBody(self::COMMENT_BODIES),
                    'status' => CommentStatus::Visible->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $topLevel->push((object) ['id' => $id, 'user_id' => $user->id]);
                $allComments->push((object) ['id' => $id, 'user_id' => $user->id]);
            }

            $level2 = collect();
            foreach ($topLevel as $parent) {
                $replyUsers = $users
                    ->where('id', '!=', $post->user_id)
                    ->shuffle()
                    ->take($this->replyCount());
                foreach ($replyUsers as $user) {
                    $id = DB::table('comments')->insertGetId([
                        'post_id' => $post->id,
                        'user_id' => $user->id,
                        'parent_id' => $parent->id,
                        'body' => $this->randomBody(self::REPLY_BODIES),
                        'status' => CommentStatus::Visible->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $level2->push((object) ['id' => $id, 'user_id' => $user->id]);
                    $allComments->push((object) ['id' => $id, 'user_id' => $user->id]);
                }
            }

            $deepCount = min($level2->count(), $this->deepReplyParentCount());
            foreach ($level2->random($deepCount) as $parent) {
                $replyUsers = $users
                    ->where('id', '!=', $post->user_id)
                    ->shuffle()
                    ->take($this->deepReplyCount());
                foreach ($replyUsers as $user) {
                    $id = DB::table('comments')->insertGetId([
                        'post_id' => $post->id,
                        'user_id' => $user->id,
                        'parent_id' => $parent->id,
                        'body' => $this->randomBody(self::REPLY_BODIES),
                        'status' => CommentStatus::Visible->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $allComments->push((object) ['id' => $id, 'user_id' => $user->id]);
                }
            }

            $count = DB::table('comments')
                ->where('post_id', $post->id)
                ->where('status', CommentStatus::Visible->value)
                ->count();

            DB::table('posts')->where('id', $post->id)->update(['comments_count' => $count]);

            $this->command->getOutput()->write('.');
        }

        $this->command->getOutput()->writeln('');

        return $allComments;
    }

    // -------------------------------------------------------------------------
    // Comment votes (bulk insert, batch counter update)
    // -------------------------------------------------------------------------

    private function seedCommentVotes(
        Collection $users,
        Collection $comments,
    ): void {
        $now = now()->toDateTimeString();
        $commentVotes = [];

        foreach ($comments as $comment) {
            $voters = $users
                ->where('id', '!=', $comment->user_id)
                ->shuffle()
                ->take((int) round($users->count() * $this->commentVoteRatio()));

            foreach ($voters as $user) {
                $commentVotes[] = [
                    'comment_id' => $comment->id,
                    'user_id' => $user->id,
                    'type' => fake()->boolean(75) ? VoteType::Up->value : VoteType::Down->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($commentVotes) >= 1000) {
                DB::table('comment_votes')->upsert($commentVotes, ['comment_id', 'user_id'], ['type', 'updated_at']);
                $commentVotes = [];
                $this->command->getOutput()->write('.');
            }
        }

        if ($commentVotes !== []) {
            DB::table('comment_votes')->upsert($commentVotes, ['comment_id', 'user_id'], ['type', 'updated_at']);
        }

        $this->command->getOutput()->writeln('');

        $counters = app(RecalculateCommentCountersAction::class);

        Comment::query()
            ->whereIn('id', $comments->pluck('id'))
            ->select('id')
            ->lazyById()
            ->each(fn (Comment $comment) => $counters->handle($comment));
    }

    // -------------------------------------------------------------------------
    // Post counter recalculation
    // -------------------------------------------------------------------------

    private function recalculatePosts(Collection $posts): void
    {
        $counters = app(RecalculatePostCountersAction::class);
        $score = app(RecalculatePostScoreAction::class);

        foreach ($posts as $post) {
            $post->refresh();
            $counters->handle($post);
            $post->refresh();
            $score->handle($post);
        }
    }

    private function clearGeneratedInteractions(Collection $users, Collection $posts): void
    {
        $userIds = $users->modelKeys();
        $postIds = $posts->modelKeys();

        // Community FKs restrict instead of cascading (PR-C), so this
        // dev-only reseed cleanup must delete the child graph explicitly
        // and in dependency order: votes on the generated comments first,
        // then the comments themselves leaves-first — reply chains can be
        // arbitrarily deep here, and MariaDB/SQLite enforce the
        // self-referencing parent_id RESTRICT row by row, so a parent must
        // only be deleted once none of its children remain.
        $remainingIds = Comment::withTrashed()
            ->whereIn('post_id', $postIds)
            ->whereIn('user_id', $userIds)
            ->pluck('id')
            ->all();

        CommentVote::query()
            ->whereIn('comment_id', $remainingIds)
            ->delete();

        while ($remainingIds !== []) {
            $idsWithChildren = Comment::withTrashed()
                ->whereIn('parent_id', $remainingIds)
                ->pluck('parent_id')
                ->unique()
                ->all();

            $leafIds = array_values(array_diff($remainingIds, $idsWithChildren));

            if ($leafIds === []) {
                // Remaining comments have replies outside the generated
                // set; deleting them would (correctly) hit the FK anyway.
                break;
            }

            Comment::withTrashed()->whereIn('id', $leafIds)->forceDelete();

            $remainingIds = array_values(array_intersect($remainingIds, $idsWithChildren));
        }

        PostVote::query()
            ->whereIn('post_id', $postIds)
            ->whereIn('user_id', $userIds)
            ->delete();

        RatingVote::query()
            ->whereIn('post_id', $postIds)
            ->whereIn('user_id', $userIds)
            ->delete();
    }

    /**
     * Bulk directory enumeration + deletion by filename pattern — outside
     * MediaStorage's narrow, single-location contract (store/exists/size/
     * read/delete), so this stays a direct Storage:: call.
     */
    private function clearGeneratedMedia(): void
    {
        $disk = Storage::disk((string) config('media.disks.public'));
        $paths = array_values(array_filter(
            $disk->allFiles('posts'),
            fn (string $path): bool => preg_match('#/fill_post_\d+\.jpg$#', $path) === 1,
        ));

        if ($paths !== []) {
            $disk->delete($paths);
        }
    }

    protected function userCount(): int
    {
        return self::USER_COUNT;
    }

    /** @return list<string> */
    protected function postTitles(): array
    {
        return self::POST_TITLES;
    }

    protected function voteRatio(): float
    {
        return self::VOTE_RATIO;
    }

    protected function commentVoteRatio(): float
    {
        return self::COMMENT_VOTE_RATIO;
    }

    protected function topLevelCommentCount(): int
    {
        return fake()->numberBetween(6, 12);
    }

    protected function replyCount(): int
    {
        return fake()->numberBetween(2, 5);
    }

    protected function deepReplyParentCount(): int
    {
        return fake()->numberBetween(3, 8);
    }

    protected function deepReplyCount(): int
    {
        return fake()->numberBetween(1, 3);
    }

    private function percentage(float $ratio): int
    {
        return (int) round($ratio * 100);
    }

    private function randomBody(array $pool): string
    {
        return $pool[array_rand($pool)];
    }
}
