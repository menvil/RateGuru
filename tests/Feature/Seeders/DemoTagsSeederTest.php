<?php

use App\Models\Tag;
use Database\Seeders\DemoTagsSeeder;

it('seeds demo tags', function () {
    $this->seed(DemoTagsSeeder::class);

    expect(Tag::query()->where('slug', 'category-a')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'category-b')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'source-a')->exists())->toBeTrue();
});

it('seeds tags with unique slugs', function () {
    $this->seed(DemoTagsSeeder::class);

    $total = Tag::query()->count();
    $uniqueSlugs = Tag::query()->distinct('slug')->count('slug');

    expect($uniqueSlugs)->toBe($total);
});

it('seeds at least ten url safe tags idempotently', function () {
    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoTagsSeeder::class);

    expect(Tag::query()->count())->toBeGreaterThanOrEqual(10);
    expect(Tag::query()->where('slug', 'sample-a')->count())->toBe(1);

    Tag::query()->pluck('slug')->each(function (string $slug) {
        expect($slug)->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/');
    });
});
