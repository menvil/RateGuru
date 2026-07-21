<?php

namespace Database\Seeders;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\Support\DemoPostMediaGenerator;
use Illuminate\Database\Seeder;

class DemoPendingPostsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->posts() as $index => $demoPost) {
            $author = User::query()->where('email', $demoPost['author'])->firstOrFail();
            app(DemoPostMediaGenerator::class)->create($demoPost['image_path'], $index + 14);

            $post = Post::query()->updateOrCreate(
                ['title' => $demoPost['title']],
                [
                    'user_id' => $author->id,
                    'description' => $demoPost['description'],
                    'image_path' => $demoPost['image_path'],
                    'image_url' => null,
                    'thumbnail_url' => null,
                    'source_url' => null,
                    'status' => PostStatus::Pending,
                    'published_at' => null,
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
                'title' => 'Demo Pending: Needs Moderation 01',
                'description' => 'A newly submitted sample post waiting for moderator approval.',
                'image_path' => 'demo/posts/pending-01.svg',
                'author' => 'alice@rateguru.test',
                'tags' => ['category-a', 'source-a'],
            ],
            [
                'title' => 'Demo Pending: Needs Moderation 02',
                'description' => 'A source B sample post waiting in the pending moderation queue.',
                'image_path' => 'demo/posts/pending-02.svg',
                'author' => 'bob@rateguru.test',
                'tags' => ['category-b', 'source-b'],
            ],
            [
                'title' => 'Demo Pending: Needs Moderation 03',
                'description' => 'A category D submission that should not appear in public feed yet.',
                'image_path' => 'demo/posts/pending-03.svg',
                'author' => 'carla@rateguru.test',
                'tags' => ['category-d', 'sample-c'],
            ],
        ];
    }
}
