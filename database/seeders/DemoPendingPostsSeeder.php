<?php

namespace Database\Seeders;

use App\Enums\CuisineType;
use App\Enums\OriginType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoPendingPostsSeeder extends Seeder
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
                    'status' => PostStatus::Pending,
                    'origin_truth' => $demoPost['origin_truth'],
                    'cuisine_truth' => $demoPost['cuisine_truth'],
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
     *     origin_truth: OriginType,
     *     cuisine_truth: CuisineType,
     *     tags: list<string>
     * }>
     */
    private function posts(): array
    {
        return [
            [
                'title' => 'Demo Pending: Needs Moderation 01',
                'description' => 'A newly submitted pasta plate waiting for moderator approval.',
                'image_path' => 'demo/posts/pending-01.jpg',
                'author' => 'alice@rateguru.test',
                'origin_truth' => OriginType::Homemade,
                'cuisine_truth' => CuisineType::Italian,
                'tags' => ['italian', 'homemade'],
            ],
            [
                'title' => 'Demo Pending: Needs Moderation 02',
                'description' => 'A restaurant bowl waiting in the pending moderation queue.',
                'image_path' => 'demo/posts/pending-02.jpg',
                'author' => 'bob@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Asian,
                'tags' => ['asian', 'restaurant'],
            ],
            [
                'title' => 'Demo Pending: Needs Moderation 03',
                'description' => 'A spicy street-food submission that should not appear in public feed yet.',
                'image_path' => 'demo/posts/pending-03.jpg',
                'author' => 'carla@rateguru.test',
                'origin_truth' => OriginType::Restaurant,
                'cuisine_truth' => CuisineType::Mexican,
                'tags' => ['mexican', 'street-food', 'spicy'],
            ],
        ];
    }
}
