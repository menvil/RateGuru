<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'image_asset_id' => null,
            'source_url' => null,

            'status' => PostStatus::Pending,

            'upvotes_count' => 0,
            'downvotes_count' => 0,
            'comments_count' => 0,
            'reports_count' => 0,
            'hot_score' => 0,

            'published_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Pending,
            'published_at' => null,
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Rejected,
            'published_at' => null,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Hidden,
            'published_at' => now(),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => PostStatus::Published,
            'published_at' => now(),
        ]);
    }

    /**
     * Attaches a real MediaAsset (kind: post_image) as this post's image.
     * Pass path/disk to control the exact identity assertions in a test rely on.
     */
    public function withImage(?string $path = null, ?string $disk = null, ?int $width = null, ?int $height = null): static
    {
        return $this->state(fn (): array => [
            'image_asset_id' => MediaAsset::factory()
                ->postImage()
                ->dimensions($width ?? 1600, $height ?? 900)
                ->create([
                    'disk' => $disk ?? 'public',
                    'path' => $path ?? 'posts/'.fake()->uuid().'.jpg',
                ])->id,
        ]);
    }
}
