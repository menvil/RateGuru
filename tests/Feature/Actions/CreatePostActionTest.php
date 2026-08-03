<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ImageOrientation;
use App\Enums\MediaKind;
use App\Enums\MediaStatus;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Exceptions\Posts\CannotCreatePostException;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use App\Services\Images\ImageStorage;
use App\Services\Images\StoredMedia;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

it('creates a published post for default trusted user', function () {
    $user = User::factory()->create();

    $data = new CreatePostData(
        title: 'Sample entry',
        description: null,
        sourceUrl: null,
        tagIds: [],
        image: null,
    );

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post)->toBeInstanceOf(Post::class);
    expect($post->exists)->toBeTrue();
    expect($post->user_id)->toBe($user->id);
    expect($post->title)->toBe('Sample entry');
    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});

it('creates a published post for trusted user', function () {
    $user = User::factory()->trusted()->create();

    $data = new CreatePostData(title: 'Trusted dish');

    $post = app(CreatePostAction::class)->handle($user, $data);

    expect($post->status)->toBe(PostStatus::Published);
    expect($post->published_at)->not->toBeNull();
});

it('does not allow banned user to create post', function () {
    $user = User::factory()->banned()->create();

    $data = new CreatePostData(title: 'Blocked dish');

    try {
        app(CreatePostAction::class)->handle($user, $data);
        $this->fail('Expected CannotCreatePostException was not thrown.');
    } catch (CannotCreatePostException) {
        expect(Post::query()->count())->toBe(0);
    }
});

it('persists post description', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Sample entry',
        description: 'Fresh pasta with tomato sauce',
    ));

    expect($post->fresh()->description)->toBe('Fresh pasta with tomato sauce');
});

it('persists source url', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Sample entry',
        sourceUrl: 'https://example.com/original',
    ));

    expect($post->fresh()->source_url)->toBe('https://example.com/original');
});

it('attaches tags to created post', function () {
    $user = User::factory()->create();
    $tags = Tag::factory()->count(2)->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Tagged dish',
        tagIds: $tags->pluck('id')->all(),
    ));

    expect($post->tags()->count())->toBe(2);
    expect($post->tags()->pluck('id')->all())
        ->toEqualCanonicalizing($tags->pluck('id')->all());
});

it('does not create a media asset when no image is provided', function () {
    $user = User::factory()->create();

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish without an image',
        image: null,
    ));

    expect($post->fresh()->image_asset_id)->toBeNull();
});

it('creates a post_image media asset from the stored file when an image is provided', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 1600, 900);

    $fakeStorage = new class implements ImageStorage
    {
        public bool $storePostImageCalled = false;

        public function storePostImage(UploadedFile $file, User $user): StoredMedia
        {
            $this->storePostImageCalled = true;

            return new StoredMedia(
                disk: 'public',
                path: 'posts/1/dish.jpg',
                originalFilename: 'dish.jpg',
                mimeType: 'image/jpeg',
                extension: 'jpg',
                byteSize: 123_456,
                width: 1600,
                height: 900,
            );
        }

        public function storeAvatar(UploadedFile $file, User $user): StoredMedia
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function delete(StoredMedia $media): void
        {
            //
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    ));

    expect($fakeStorage->storePostImageCalled)->toBeTrue();

    $post = $post->fresh();
    expect($post->image_asset_id)->not->toBeNull();

    $asset = $post->imageAsset;
    expect($asset->kind)->toBe(MediaKind::PostImage)
        ->and($asset->owner_user_id)->toBe($user->id)
        ->and($asset->disk)->toBe('public')
        ->and($asset->path)->toBe('posts/1/dish.jpg')
        ->and($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->byte_size)->toBe(123_456)
        ->and($asset->width)->toBe(1600)
        ->and($asset->height)->toBe(900)
        ->and($asset->orientation)->toBe(ImageOrientation::Landscape)
        ->and($asset->status)->toBe(MediaStatus::Ready)
        ->and($asset->visibility)->toBe(MediaVisibility::Public);
});

it('creates a media asset with null dimensions and orientation when the file cannot be measured', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $fakeStorage = new class implements ImageStorage
    {
        public function storePostImage(UploadedFile $file, User $user): StoredMedia
        {
            return new StoredMedia(
                disk: 'public',
                path: 'posts/1/dish.jpg',
                originalFilename: 'dish.jpg',
                mimeType: 'image/jpeg',
                extension: 'jpg',
                byteSize: 100,
                width: null,
                height: null,
            );
        }

        public function storeAvatar(UploadedFile $file, User $user): StoredMedia
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function delete(StoredMedia $media): void
        {
            //
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with unmeasurable image',
        image: $file,
    ));

    $asset = $post->fresh()->imageAsset;
    expect($asset->width)->toBeNull()
        ->and($asset->height)->toBeNull()
        ->and($asset->aspect_ratio)->toBeNull()
        ->and($asset->orientation)->toBeNull();
});

it('creates a media asset with null aspect ratio and orientation instead of dividing by zero for a degenerate height', function () {
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $fakeStorage = new class implements ImageStorage
    {
        public function storePostImage(UploadedFile $file, User $user): StoredMedia
        {
            return new StoredMedia(
                disk: 'public',
                path: 'posts/1/dish.jpg',
                originalFilename: 'dish.jpg',
                mimeType: 'image/jpeg',
                extension: 'jpg',
                byteSize: 100,
                width: 800,
                height: 0,
            );
        }

        public function storeAvatar(UploadedFile $file, User $user): StoredMedia
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function delete(StoredMedia $media): void
        {
            //
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with degenerate dimensions',
        image: $file,
    ));

    $asset = $post->fresh()->imageAsset;
    expect($asset->width)->toBe(800)
        ->and($asset->height)->toBe(0)
        ->and($asset->aspect_ratio)->toBeNull()
        ->and($asset->orientation)->toBeNull();
});

it('keeps the stored post image file after a successful creation', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    ));

    $asset = $post->fresh()->imageAsset;
    Storage::disk('public')->assertExists($asset->path);
});

it('deletes the newly stored post image file when the database transaction fails', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    // Force a genuine DB failure after the file has already been written but
    // before the post commits, by colliding with the unique(disk, path)
    // constraint on media_assets — the exact failure mode the compensation
    // logic exists to handle.
    MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'posts/1/dish.jpg',
    ]);

    $fakeStorage = new class implements ImageStorage
    {
        public function storePostImage(UploadedFile $file, User $user): StoredMedia
        {
            Storage::disk('public')->put('posts/1/dish.jpg', 'fake-bytes');

            return new StoredMedia(
                disk: 'public',
                path: 'posts/1/dish.jpg',
                originalFilename: 'dish.jpg',
                mimeType: 'image/jpeg',
                extension: 'jpg',
                byteSize: 100,
                width: 800,
                height: 600,
            );
        }

        public function storeAvatar(UploadedFile $file, User $user): StoredMedia
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function delete(StoredMedia $media): void
        {
            Storage::disk($media->disk)->delete($media->path);
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    )))->toThrow(QueryException::class);

    expect(Post::query()->count())->toBe(0);
    expect(MediaAsset::query()->count())->toBe(1);
    Storage::disk('public')->assertMissing('posts/1/dish.jpg');
});

it('propagates the original database exception and separately reports a cleanup failure', function () {
    Storage::fake('public');
    Exceptions::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'posts/1/dish.jpg',
    ]);

    $fakeStorage = new class implements ImageStorage
    {
        public function storePostImage(UploadedFile $file, User $user): StoredMedia
        {
            return new StoredMedia(
                disk: 'public',
                path: 'posts/1/dish.jpg',
                originalFilename: 'dish.jpg',
                mimeType: 'image/jpeg',
                extension: 'jpg',
                byteSize: 100,
                width: 800,
                height: 600,
            );
        }

        public function storeAvatar(UploadedFile $file, User $user): StoredMedia
        {
            throw new RuntimeException('Not used in this test.');
        }

        public function delete(StoredMedia $media): void
        {
            throw new RuntimeException('Simulated cleanup failure.');
        }
    };

    app()->instance(ImageStorage::class, $fakeStorage);

    // The original database exception must be what propagates — the
    // cleanup failure must never replace or suppress it.
    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    )))->toThrow(QueryException::class);

    expect(Post::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});
