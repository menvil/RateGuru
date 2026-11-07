<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostSave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostSave>
 */
class PostSaveFactory extends Factory
{
    protected $model = PostSave::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'post_id' => Post::factory()->published(),
        ];
    }
}
