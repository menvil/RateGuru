<?php

use App\Jobs\ProcessUploadedImageJob;
use App\Models\Post;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

it('creates a bounded 1200 by 630 jpeg social image for a new local upload', function () {
    Storage::fake('public');
    config(['rateguru.images.disk' => 'public']);

    $source = UploadedFile::fake()->image('portrait.png', 600, 1200);
    $sourcePath = $source->storeAs('posts/1', 'portrait.png', 'public');
    $sourceHash = hash_file('sha256', Storage::disk('public')->path($sourcePath));

    $post = Post::factory()->published()->create([
        'image_path' => $sourcePath,
        'image_url' => null,
        'og_image_path' => null,
    ]);

    app()->call([new ProcessUploadedImageJob($post->id), 'handle']);

    $post->refresh();

    expect($post->og_image_path)->toBe("posts/{$post->user_id}/og/{$post->id}.jpg");
    Storage::disk('public')->assertExists($post->og_image_path);

    $metadata = getimagesize(Storage::disk('public')->path($post->og_image_path));

    expect($metadata)->not->toBeFalse()
        ->and($metadata[0])->toBe(1200)
        ->and($metadata[1])->toBe(630)
        ->and($metadata['mime'])->toBe('image/jpeg')
        ->and(Storage::disk('public')->size($post->og_image_path))->toBeLessThanOrEqual(700 * 1024)
        ->and(hash_file('sha256', Storage::disk('public')->path($sourcePath)))->toBe($sourceHash);
});

it('leaves a post without a local upload unchanged', function () {
    Storage::fake('public');

    $post = Post::factory()->published()->create([
        'image_path' => null,
        'image_url' => 'https://cdn.example.com/post.jpg',
        'og_image_path' => null,
    ]);

    app()->call([new ProcessUploadedImageJob($post->id), 'handle']);

    expect($post->fresh()->og_image_path)->toBeNull();
});

it('reports a corrupt local upload without saving an open graph path', function () {
    Storage::fake('public');
    config(['rateguru.images.disk' => 'public']);
    Exceptions::fake();

    $sourcePath = 'posts/1/corrupt.jpg';
    Storage::disk('public')->put($sourcePath, 'not an image');

    $post = Post::factory()->published()->create([
        'image_path' => $sourcePath,
        'og_image_path' => null,
    ]);

    app()->call([new ProcessUploadedImageJob($post->id), 'handle']);

    expect($post->fresh()->og_image_path)->toBeNull();

    Exceptions::assertReported(
        fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'cannot be decoded'),
    );
});
