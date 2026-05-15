<?php

namespace Database\Factories;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
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
            'image_path' => null,
            'image_url' => null,
            'thumbnail_url' => null,
            'source_url' => null,

            'status' => PostStatus::Pending,
            'origin_truth' => OriginType::Unknown,
            'cuisine_truth' => CuisineType::Unknown,

            'upvotes_count' => 0,
            'downvotes_count' => 0,
            'homemade_votes_count' => 0,
            'restaurant_votes_count' => 0,
            'comments_count' => 0,
            'reports_count' => 0,
            'hot_score' => 0,

            'published_at' => null,
        ];
    }
}
