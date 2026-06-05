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
            ['name' => 'Category A', 'slug' => 'category-a'],
            ['name' => 'Category B', 'slug' => 'category-b'],
            ['name' => 'Category C', 'slug' => 'category-c'],
            ['name' => 'Category D', 'slug' => 'category-d'],
            ['name' => 'Source A', 'slug' => 'source-a'],
            ['name' => 'Source B', 'slug' => 'source-b'],
            ['name' => 'Sample A', 'slug' => 'sample-a'],
            ['name' => 'Sample B', 'slug' => 'sample-b'],
            ['name' => 'Sample C', 'slug' => 'sample-c'],
            ['name' => 'Sample D', 'slug' => 'sample-d'],
            ['name' => 'Sample E', 'slug' => 'sample-e'],
            ['name' => 'Sample F', 'slug' => 'sample-f'],
        ];
    }
}
