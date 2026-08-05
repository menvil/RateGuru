<?php

use App\Actions\Posts\CreatePostAction;
use App\Data\Posts\CreatePostData;
use App\Enums\ImageInputSource;
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
use App\Services\Media\Exceptions\ImageIngestException;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageInput;
use App\Services\Media\MediaStorage;
use App\Services\Media\NormalizedImage;
use App\Services\Media\StoredMedia;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

function fakeUploadWithBytes(string $bytes, string $originalName, string $mimeType): UploadedFile
{
    $tmpPath = sys_get_temp_dir().'/rg_test_'.uniqid().'.tmp';
    file_put_contents($tmpPath, $bytes);
    register_shutdown_function(static fn () => @unlink($tmpPath));

    return new UploadedFile(
        path: $tmpPath,
        originalName: $originalName,
        mimeType: $mimeType,
        error: UPLOAD_ERR_OK,
        test: true,
    );
}

function tinyJpegBytes(int $width = 100, int $height = 80): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 10, 20, 30));
    ob_start();
    imagejpeg($im, null, 90);

    return ob_get_clean();
}

function fakeStoredPostImage(
    string $path = 'posts/1/dish.jpg',
    ?int $width = 1600,
    ?int $height = 900,
): StoredMedia {
    return new StoredMedia(
        disk: 'public',
        path: $path,
        originalFilename: 'dish.jpg',
        mimeType: 'image/jpeg',
        extension: 'jpg',
        byteSize: 123_456,
        width: $width,
        height: $height,
    );
}

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

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('storeNormalized')
        ->once()
        ->with(
            Mockery::type(NormalizedImage::class),
            Mockery::on(fn ($request) => $request->ownerUserId === $user->id),
            'dish.jpg',
        )
        ->andReturn(fakeStoredPostImage());
    app()->instance(MediaStorage::class, $mediaStorage);

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    ));

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

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('storeNormalized')->once()->andReturn(
        fakeStoredPostImage(width: null, height: null),
    );
    app()->instance(MediaStorage::class, $mediaStorage);

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

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('storeNormalized')->once()->andReturn(
        fakeStoredPostImage(width: 800, height: 0),
    );
    app()->instance(MediaStorage::class, $mediaStorage);

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

    // Force a genuine DB failure after the image asset has already been
    // created (so the file has no other owner) but before the post commits,
    // via a tag id that violates post_tag's foreign key constraint.
    $post = app(CreatePostAction::class);

    expect(fn () => $post->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
        tagIds: [999_999],
    )))->toThrow(QueryException::class);

    expect(Post::query()->count())->toBe(0);
    expect(MediaAsset::query()->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('posts');
});

it('propagates the original database exception and separately reports a cleanup failure', function () {
    Storage::fake('public');
    Exceptions::fake();

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('storeNormalized')->once()->andReturn(
        fakeStoredPostImage(width: 800, height: 600),
    );
    $mediaStorage->shouldReceive('delete')->once()->andThrow(
        new RuntimeException('Simulated cleanup failure.'),
    );
    app()->instance(MediaStorage::class, $mediaStorage);

    // The original database exception must be what propagates — the
    // cleanup failure must never replace or suppress it. A nonexistent tag
    // id forces a genuine FK failure with no path collision involved.
    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
        tagIds: [999_999],
    )))->toThrow(QueryException::class);

    expect(Post::query()->count())->toBe(0);
    Exceptions::assertReported(RuntimeException::class);
});

it('does not delete another asset\'s file when the new upload collides on its path', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    // Simulates a path collision: some other, already-committed upload
    // legitimately owns this exact (disk, path) — its file must survive
    // even though this request's own insert fails because of it.
    Storage::disk('public')->put('posts/1/dish.jpg', 'existing-owner-bytes');
    MediaAsset::factory()->create([
        'disk' => 'public',
        'path' => 'posts/1/dish.jpg',
    ]);

    $mediaStorage = Mockery::mock(MediaStorage::class);
    $mediaStorage->shouldReceive('storeNormalized')->once()->andReturn(
        fakeStoredPostImage(width: 800, height: 600),
    );
    $mediaStorage->shouldReceive('delete')->never();
    app()->instance(MediaStorage::class, $mediaStorage);

    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with image',
        image: $file,
    )))->toThrow(QueryException::class);

    expect(Post::query()->count())->toBe(0);
    expect(MediaAsset::query()->count())->toBe(1);
    Storage::disk('public')->assertExists('posts/1/dish.jpg');
    expect(Storage::disk('public')->get('posts/1/dish.jpg'))->toBe('existing-owner-bytes');
});

it('ignores a misleading client extension and determines the stored extension from actual content', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = fakeUploadWithBytes(tinyJpegBytes(), 'totally-a-photo.png', 'image/png');

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with mislabeled image',
        image: $file,
    ));

    $asset = $post->fresh()->imageAsset;
    expect($asset->mime_type)->toBe('image/jpeg')
        ->and($asset->extension)->toBe('jpg')
        ->and($asset->path)->toEndWith('.jpg');
});

it('strips EXIF metadata from the stored post image', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    $plain = tinyJpegBytes();
    $tiffHeader = "II\x2A\x00".pack('V', 8);
    $entry = pack('vvV', 0x0112, 3, 1).pack('v', 1)."\x00\x00";
    $ifd0 = pack('v', 1).$entry.pack('V', 0);
    $app1Payload = "Exif\x00\x00".$tiffHeader.$ifd0;
    $app1 = "\xFF\xE1".pack('n', strlen($app1Payload) + 2).$app1Payload;
    $withExif = substr($plain, 0, 2).$app1.substr($plain, 2);

    $file = fakeUploadWithBytes($withExif, 'dish.jpg', 'image/jpeg');

    $post = app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with EXIF',
        image: $file,
    ));

    $storedBytes = Storage::disk('public')->get($post->fresh()->imageAsset->path);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $storedBytes);
    rewind($stream);
    $exif = @exif_read_data($stream);
    fclose($stream);

    expect($exif === false || ! isset($exif['Orientation']))->toBeTrue();
});

it('creates no post, asset, or file when the image cannot be ingested', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->create('not-an-image.jpg', 10);

    expect(fn () => app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish with a broken image',
        image: $file,
    )))->toThrow(ImageIngestException::class);

    expect(Post::query()->count())->toBe(0);
    expect(MediaAsset::query()->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('posts');
});

it('passes the URL-import source through to ImageIngestor for an imported image', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $imageIngestor = Mockery::mock(ImageIngestor::class);
    $imageIngestor->shouldReceive('ingest')
        ->once()
        ->with(Mockery::on(fn (ImageInput $input): bool => $input->source === ImageInputSource::UrlImport), Mockery::any())
        ->andReturn(normalizedFixture());
    app()->instance(ImageIngestor::class, $imageIngestor);

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish from an imported url',
        image: $file,
        imageSource: ImageInputSource::UrlImport,
    ));
});

it('defaults to the Upload source when a post image is provided without an explicit source', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg', 800, 600);

    $imageIngestor = Mockery::mock(ImageIngestor::class);
    $imageIngestor->shouldReceive('ingest')
        ->once()
        ->with(Mockery::on(fn (ImageInput $input): bool => $input->source === ImageInputSource::Upload), Mockery::any())
        ->andReturn(normalizedFixture());
    app()->instance(ImageIngestor::class, $imageIngestor);

    app(CreatePostAction::class)->handle($user, new CreatePostData(
        title: 'Dish uploaded directly',
        image: $file,
    ));
});
