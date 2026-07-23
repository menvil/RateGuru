<?php

use App\Models\Tag;
use Database\Seeders\DemoTagsSeeder;

it('seeds demo tags', function () {
    $this->seed(DemoTagsSeeder::class);

    expect(Tag::query()->where('slug', 'featured')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'community')->exists())->toBeTrue();
    expect(Tag::query()->where('slug', 'original')->exists())->toBeTrue();
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

it('removes only obsolete demo taxonomy tags when reseeded', function () {
    Tag::factory()->create(['slug' => 'category-a']);
    Tag::factory()->create(['slug' => 'source-b']);
    $unrelated = Tag::factory()->create(['slug' => 'user-owned']);

    $this->seed(DemoTagsSeeder::class);
    $this->seed(DemoTagsSeeder::class);

    expect(Tag::query()->whereIn('slug', ['category-a', 'source-b'])->exists())
        ->toBeFalse()
        ->and($unrelated->fresh())->not->toBeNull()
        ->and(Tag::query()->where('slug', 'featured')->exists())->toBeTrue();
});

it('rolls back obsolete tag cleanup when demo tag seeding fails', function () {
    $legacyTag = Tag::factory()->create(['slug' => 'source-b']);
    $exception = null;

    Tag::creating(function (Tag $tag): void {
        if ($tag->slug === 'featured') {
            throw new RuntimeException('Simulated demo tag failure.');
        }
    });

    try {
        app(DemoTagsSeeder::class)->run();
    } catch (RuntimeException $caught) {
        $exception = $caught;
    } finally {
        Tag::flushEventListeners();
    }

    expect($exception)->not->toBeNull()
        ->and($legacyTag->fresh())->not->toBeNull();
});
