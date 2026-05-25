<?php

namespace Database\Seeders;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoPublishedPostsSeeder extends Seeder
{
    private const BASE_PUBLISHED_AT = '2026-05-20 12:00:00';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->posts() as $index => $demoPost) {
            $author = User::query()->where('email', $demoPost['author'])->firstOrFail();

            $post = Post::query()->updateOrCreate(
                ['title' => $demoPost['title']],
                [
                    'user_id' => $author->id,
                    'description' => $demoPost['description'],
                    'image_path' => $demoPost['image_path'],
                    'image_url' => null,
                    'thumbnail_url' => null,
                    'source_url' => null,
                    'status' => PostStatus::Published,
                    'origin_truth' => $demoPost['origin_truth'],
                    'cuisine_truth' => $demoPost['cuisine_truth'],
                    'published_at' => CarbonImmutable::parse(self::BASE_PUBLISHED_AT)->subHours($index + 1),
                ],
            );

            $tags = Tag::query()
                ->whereIn('slug', $demoPost['tags'])
                ->get(['id', 'slug']);

            $missingSlugs = array_values(array_diff($demoPost['tags'], $tags->pluck('slug')->all()));

            if ($missingSlugs !== []) {
                throw new RuntimeException('Missing demo post tags: '.implode(', ', $missingSlugs));
            }

            $post->tags()->sync($tags->pluck('id')->all());
        }
    }

    /**
     * @return list<array{
     *     title: string,
     *     description: string,
     *     image_path: string,
     *     author: string,
     *     origin_truth: OriginType,
     *     cuisine_truth: CuisineType,
     *     tags: list<string>
     * }>
     */
    private function posts(): array
    {
        return [
            [
                'title' => 'Demo: Homemade Italian Pasta',
                'description' => 'Fresh pasta with tomato sauce, basil, and a bright homemade finish.',
                'image_path' => 'demo/posts/pasta.jpg',
                'author' => 'alice@rateguru.test',
                'origin_truth' => OriginType::Homemade,
                'cuisine_truth' => CuisineType::Italian,
                'tags' => ['italian', 'homemade', 'comfort-food'],
            ],
            [
                'title' => 'Demo: Restaurant Sushi Plate',
                'description' => 'Assorted sushi from a neighborhood restaurant for cuisine voting checks.',
                'image_path' => 'demo/posts/sushi.jpg',
                'author' => 'bob@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Asian,
                'tags' => ['asian', 'restaurant', 'healthy'],
            ],
            [
                'title' => 'Demo: Mexican Street Tacos',
                'description' => 'Corn tortillas, salsa, cilantro, and a street-food presentation.',
                'image_path' => 'demo/posts/tacos.jpg',
                'author' => 'carla@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Mexican,
                'tags' => ['mexican', 'street-food', 'spicy'],
            ],
            [
                'title' => 'Demo: American Breakfast Stack',
                'description' => 'Pancakes, eggs, and syrup for breakfast feed and profile checks.',
                'image_path' => 'demo/posts/breakfast.jpg',
                'author' => 'trusted@rateguru.test',
                'origin_truth' => OriginType::Homemade,
                'cuisine_truth' => CuisineType::American,
                'tags' => ['american', 'breakfast', 'homemade'],
            ],
            [
                'title' => 'Demo: Healthy Asian Bowl',
                'description' => 'Rice, vegetables, and savory sauce with a clean lunch-bowl layout.',
                'image_path' => 'demo/posts/asian-bowl.jpg',
                'author' => 'alice@rateguru.test',
                'origin_truth' => OriginType::Homemade,
                'cuisine_truth' => CuisineType::Asian,
                'tags' => ['asian', 'healthy', 'homemade'],
            ],
            [
                'title' => 'Demo: Chocolate Dessert Plate',
                'description' => 'A plated chocolate dessert for dessert search and ranking checks.',
                'image_path' => 'demo/posts/chocolate-dessert.jpg',
                'author' => 'bob@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Other,
                'tags' => ['dessert', 'restaurant', 'comfort-food'],
            ],
        ];
    }
}
