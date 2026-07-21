<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\DemoPostMediaGenerator;
use Illuminate\Database\Seeder;

class DemoHiddenPostsSeeder extends Seeder
{
    private const PUBLISHED_AT = '2026-05-20 12:00:00';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->posts() as $index => $demoPost) {
            $author = User::query()->where('email', $demoPost['author'])->firstOrFail();
            app(DemoPostMediaGenerator::class)->create($demoPost['image_path'], $index + 17);

            $post = Post::query()->updateOrCreate(
                ['title' => $demoPost['title']],
                [
                    'user_id' => $author->id,
                    'description' => $demoPost['description'],
                    'image_path' => $demoPost['image_path'],
                    'image_url' => null,
                    'thumbnail_url' => null,
                    'source_url' => null,
                    'status' => PostStatus::Hidden,
                    'published_at' => CarbonImmutable::parse(self::PUBLISHED_AT),
                ],
            );

            $post->tags()->sync(
                Tag::query()->whereIn('slug', $demoPost['tags'])->pluck('id')->all(),
            );
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
                'title' => 'Demo Hidden: Removed From Feed 01',
                'description' => 'A hidden source B post kept for admin moderation filter checks.',
                'image_path' => 'demo/posts/hidden-01.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['source-b', 'sample-f'],
            ],
            [
                'title' => 'Demo Hidden: Removed From Feed 02',
                'description' => 'A hidden source A post that should stay outside public feed results.',
                'image_path' => 'demo/posts/hidden-02.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['source-a', 'category-c'],
            ],
        ];
    }
}
