<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Support\Rating\RatingConfigurationManager;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoPostMediaGenerator;
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

        $categoryOptions = app(RatingConfigurationManager::class)
            ->activeGroups()
            ->first()
            ?->options
            ->values() ?? collect();

        foreach ($this->posts() as $index => $demoPost) {
            $author = User::query()->where('email', $demoPost['author'])->firstOrFail();
            app(DemoPostMediaGenerator::class)->create($demoPost['image_path'], $index);
            $categoryOption = $categoryOptions->isEmpty() || $index % 3 === 2
                ? null
                : $categoryOptions[$index % $categoryOptions->count()];

            $post = Post::query()->updateOrCreate(
                ['title' => $demoPost['title']],
                [
                    'user_id' => $author->id,
                    'description' => $demoPost['description'],
                    'image_path' => $demoPost['image_path'],
                    'image_url' => null,
                    'thumbnail_url' => null,
                    'source_url' => null,
                    'category_option_id' => $categoryOption?->id,
                    'status' => PostStatus::Published,
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
     *     tags: list<string>
     * }>
     */
    private function posts(): array
    {
        return [
            [
                'title' => 'Demo: Sample Post 01',
                'description' => 'Sample post for source and category voting checks.',
                'image_path' => 'demo/posts/sample-01.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['category-a', 'source-a', 'sample-a'],
            ],
            [
                'title' => 'Demo: Sample Post 02',
                'description' => 'Sample post for public feed and report checks.',
                'image_path' => 'demo/posts/sample-02.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['category-b', 'source-b', 'sample-b'],
            ],
            [
                'title' => 'Demo: Sample Post 03',
                'description' => 'Sample post for resolved report checks.',
                'image_path' => 'demo/posts/sample-03.svg',
                'author' => 'carla@rateguru.test',
                'tags' => ['category-d', 'source-b', 'sample-c'],
            ],
            [
                'title' => 'Demo: Sample Post 04',
                'description' => 'Sample post for feed and profile checks.',
                'image_path' => 'demo/posts/sample-04.svg',
                'author' => 'trusted@rateguru.test',
                'tags' => ['category-c', 'source-a', 'sample-d'],
            ],
            [
                'title' => 'Demo: Sample Post 05',
                'description' => 'Sample post with a clean layout for scrolling checks.',
                'image_path' => 'demo/posts/sample-05.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['category-b', 'source-a', 'sample-e'],
            ],
            [
                'title' => 'Demo: Sample Post 06',
                'description' => 'Sample post for search and ranking checks.',
                'image_path' => 'demo/posts/sample-06.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['sample-a', 'source-b', 'sample-f'],
            ],
            [
                'title' => 'Demo: Sample Post 07',
                'description' => 'Sample post for longer feed checks.',
                'image_path' => 'demo/posts/sample-07.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['category-b', 'source-a', 'sample-a'],
            ],
            [
                'title' => 'Demo: Sample Post 08',
                'description' => 'Sample post for comments and feed interactions.',
                'image_path' => 'demo/posts/sample-08.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['category-c', 'source-b', 'sample-b'],
            ],
            [
                'title' => 'Demo: Sample Post 09',
                'description' => 'Sample post for lazy loading and profile pagination checks.',
                'image_path' => 'demo/posts/sample-09.svg',
                'author' => 'trusted@rateguru.test',
                'tags' => ['category-c', 'source-a', 'sample-c'],
            ],
            [
                'title' => 'Demo: Sample Post 10',
                'description' => 'Sample post for category and source filters.',
                'image_path' => 'demo/posts/sample-10.svg',
                'author' => 'carla@rateguru.test',
                'tags' => ['category-d', 'source-b', 'sample-d'],
            ],
            [
                'title' => 'Demo: Sample Post 11',
                'description' => 'Sample post for compact card rendering checks.',
                'image_path' => 'demo/posts/sample-11.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['category-a', 'source-a', 'sample-e'],
            ],
            [
                'title' => 'Demo: Sample Post 12',
                'description' => 'Sample post for category voting checks.',
                'image_path' => 'demo/posts/sample-12.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['category-b', 'source-b', 'sample-f'],
            ],
            [
                'title' => 'Demo: Sample Post 13',
                'description' => 'Sample post for source voting checks.',
                'image_path' => 'demo/posts/sample-13.svg',
                'author' => 'trusted@rateguru.test',
                'tags' => ['category-c', 'source-a', 'sample-a'],
            ],
            [
                'title' => 'Demo: Sample Post 14',
                'description' => 'Sample post for feed scrolling and comments checks.',
                'image_path' => 'demo/posts/sample-14.svg',
                'author' => 'carla@rateguru.test',
                'tags' => ['sample-b', 'source-b', 'sample-c'],
            ],
        ];
    }
}
