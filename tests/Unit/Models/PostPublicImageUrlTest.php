<?php

use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves the public image url through the image asset disk and path', function () {
    Storage::fake('public');

    $post = Post::factory()->published()->withImage(path: 'posts/1/dish.jpg', disk: 'public')->create();

    expect($post->public_image_url)->toContain('/storage/posts/1/dish.jpg');
});

it('returns null when there is no image asset', function () {
    $post = Post::factory()->published()->create(['image_asset_id' => null]);

    expect($post->public_image_url)->toBeNull();
});

it('resolves through the asset\'s own disk rather than a hardcoded default disk', function () {
    config()->set('filesystems.disks.cdn_test', [
        'driver' => 'local',
        'root' => storage_path('app/cdn_test'),
        'url' => 'https://cdn.example.test',
        'visibility' => 'public',
    ]);

    $post = Post::factory()->published()->withImage(path: 'posts/1/dish.jpg', disk: 'cdn_test')->create();

    expect($post->public_image_url)->toBe('https://cdn.example.test/posts/1/dish.jpg');
});

it('does not reference the filesystem directly from the model', function () {
    $source = file_get_contents(app_path('Models/Post.php'));

    expect($source)
        ->not->toContain('Storage::')
        ->not->toContain(Storage::class);
});
