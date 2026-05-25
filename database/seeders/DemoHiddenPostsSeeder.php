<?php

namespace Database\Seeders;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoHiddenPostsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->posts() as $demoPost) {
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
                    'status' => PostStatus::Hidden,
                    'origin_truth' => $demoPost['origin_truth'],
                    'cuisine_truth' => $demoPost['cuisine_truth'],
                    'published_at' => now()->subDays(2),
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
     *     origin_truth: OriginType,
     *     cuisine_truth: CuisineType,
     *     tags: list<string>
     * }>
     */
    private function posts(): array
    {
        return [
            [
                'title' => 'Demo Hidden: Removed From Feed 01',
                'description' => 'A hidden restaurant post kept for admin moderation filter checks.',
                'image_path' => 'demo/posts/hidden-01.jpg',
                'author' => 'bob@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Other,
                'tags' => ['restaurant', 'comfort-food'],
            ],
            [
                'title' => 'Demo Hidden: Removed From Feed 02',
                'description' => 'A hidden homemade post that should stay outside public feed results.',
                'image_path' => 'demo/posts/hidden-02.jpg',
                'author' => 'alice@rateguru.test',
                'origin_truth' => OriginType::Homemade,
                'cuisine_truth' => CuisineType::American,
                'tags' => ['homemade', 'american'],
            ],
        ];
    }
}
