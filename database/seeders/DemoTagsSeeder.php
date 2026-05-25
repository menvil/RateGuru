<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;

class DemoTagsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        foreach ($this->tags() as $tag) {
            Tag::query()->updateOrCreate(
                ['slug' => $tag['slug']],
                ['name' => $tag['name']],
            );
        }
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    private function tags(): array
    {
        return [
            ['name' => 'Italian', 'slug' => 'italian'],
            ['name' => 'Asian', 'slug' => 'asian'],
            ['name' => 'American', 'slug' => 'american'],
            ['name' => 'Mexican', 'slug' => 'mexican'],
            ['name' => 'Homemade', 'slug' => 'homemade'],
            ['name' => 'Restaurant', 'slug' => 'restaurant'],
            ['name' => 'Dessert', 'slug' => 'dessert'],
            ['name' => 'Breakfast', 'slug' => 'breakfast'],
            ['name' => 'Street Food', 'slug' => 'street-food'],
            ['name' => 'Healthy', 'slug' => 'healthy'],
            ['name' => 'Spicy', 'slug' => 'spicy'],
            ['name' => 'Comfort Food', 'slug' => 'comfort-food'],
        ];
    }
}
