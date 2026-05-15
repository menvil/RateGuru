<?php

namespace Database\Factories;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->word() . fake()->randomNumber(4);

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
        ];
    }
}
