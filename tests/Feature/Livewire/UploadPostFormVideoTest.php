<?php

use App\Enums\MediaStatus;
use App\Enums\MediaType;
use App\Enums\MediaVisibility;
use App\Enums\PostStatus;
use App\Jobs\ProcessVideoAssetJob;
use App\Livewire\Feed\UploadPostForm;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\Exceptions\VideoTooLargeException;
use App\Services\Media\MediaLocation;
use App\Services\Media\MediaStorage;
use App\Services\Media\MediaStoreRequest;
use App\Services\Media\NormalizedImage;
use App\Services\Media\StoredMedia;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
});

it('posts a video as a draft that waits for processing, and says so', function () {
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);
    $author = User::factory()->create();

    Livewire::actingAs($author)
        ->test(UploadPostForm::class)
        ->set('title', 'A clip of the dish')
        ->set('video', fakeVideoUpload())
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null)
        ->assertSet('video', null)
        ->assertDispatched('toast', message: __('ui.upload.success_video_processing'));

    $post = Post::query()->sole();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->imageAsset->media_type)->toBe(MediaType::Video)
        // The original is stored; the temporary upload is gone (Livewire's
        // own clean-up takes the small metadata file beside it).
        ->and(array_values(array_filter(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'), fn (string $path): bool => ! str_ends_with($path, '.json'))))->toBe([]);
    Queue::assertPushed(ProcessVideoAssetJob::class);
});

it('takes a video in place of the picture, which is then not required', function () {
    bindRecordingMediaToolRunner([acceptableFfprobeResult(gif: true)]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A looping dish')
        ->set('video', fakeVideoUpload('loop.gif'))
        ->call('submit')
        ->assertHasNoErrors(['image', 'video']);

    expect(Post::query()->sole()->imageAsset->media_type)->toBe(MediaType::Animation);
});

it('still asks for a picture when neither a picture nor a video is given', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Nothing attached')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);
});

it('refuses a file of another type before any tool reads it', function () {
    $runner = bindRecordingMediaToolRunner();

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Not a video')
        ->set('video', UploadedFile::fake()->createWithContent('notes.txt', 'plain text'))
        ->call('submit')
        ->assertHasErrors(['video' => 'mimetypes']);

    expect($runner->calls)->toBe([])
        ->and(Post::query()->count())->toBe(0);
});

it('tells the author why a video was refused — too long, or unreadable — and reports none of it', function () {
    Exceptions::fake();
    bindRecordingMediaToolRunner([acceptableFfprobeResult(['duration' => '95.000000'])]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A long clip')
        ->set('video', fakeVideoUpload())
        ->call('submit')
        ->assertSet('submitError', __('ui.upload.error_video_too_long', ['seconds' => 90]));

    bindRecordingMediaToolRunner([acceptableFfprobeResult(['format_name' => 'avi'])]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'An odd container')
        ->set('video', fakeVideoUpload())
        ->call('submit')
        ->assertSet('submitError', __('ui.upload.error_invalid_video'));

    expect(Post::query()->count())->toBe(0);
    Exceptions::assertNothingReported();
});

it('says a file over the size limit is too large', function () {
    config(['uploads.videos.max_kilobytes' => 1]);
    $runner = bindRecordingMediaToolRunner();

    // The storer refuses it before any tool opens it.
    expect(fn () => app(VideoUploadStorer::class)->store(UploadedFile::fake()->create('big.mp4', 2, 'video/mp4'), 1))
        ->toThrow(VideoTooLargeException::class);
    expect($runner->calls)->toBe([])
        ->and(__('ui.upload.error_video_too_large', ['megabytes' => 100]))->toBe('This video is larger than 100 MB.');
});

it('asks for a picture when the video field holds no file at all', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'An empty video field')
        ->set('video', '')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);

    expect(Post::query()->count())->toBe(0);
});

it('tells the author to wait while earlier videos are still processing', function () {
    $author = User::factory()->create();
    MediaAsset::factory()->count(2)->create(['owner_user_id' => $author->id, 'media_type' => MediaType::Video, 'status' => MediaStatus::Processing]);
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);

    Livewire::actingAs($author)
        ->test(UploadPostForm::class)
        ->set('title', 'One more clip')
        ->set('video', fakeVideoUpload())
        ->call('submit')
        ->assertSet('submitError', __('ui.upload.error_videos_processing', ['count' => 2]));
});

it('renders a dropzone that takes pictures and videos, sends each to its own field and checks a clip\'s length first', function () {
    $html = Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->html();

    expect($html)
        ->toContain('accept="image/*,video/mp4,application/mp4,video/quicktime,video/webm,video/x-m4v,image/gif"')
        ->toContain('pickMedia($event.target.files[0])')
        ->toContain("const field = isVideo ? 'video' : 'image';")
        ->toContain('probe.onloadedmetadata')
        ->toContain('maxVideoSeconds: 90')
        ->toContain('data-testid="upload-video-preview"')
        ->toContain('data-testid="upload-progress"')
        ->toContain(e(__('ui.upload.media_drop_hint_video', ['seconds' => 90, 'megabytes' => 100])))
        // Switching to the link tab lets both fields' files go.
        ->toContain("\$wire.cancelUpload('video')");
});

it('lets Livewire take a file as large as a video, and no faster than a person sends them', function () {
    expect(config('livewire.temporary_file_upload.rules'))->toContain('max:'.config('uploads.videos.max_kilobytes'))
        ->and(config('livewire.temporary_file_upload.middleware'))->toBe('throttle:20,1')
        ->and(config('livewire.temporary_file_upload.max_upload_time'))->toBeGreaterThanOrEqual(15);
});

it('removes what a failed write of the original left, and says the failure as it was', function () {
    Storage::fake('local');
    bindRecordingMediaToolRunner([acceptableFfprobeResult()]);
    $storage = new class(app(MediaStorage::class)) implements MediaStorage
    {
        /** @var list<string> */
        public array $written = [];

        public function __construct(private MediaStorage $real) {}

        public function putFile(MediaLocation $location, string $localPath, MediaVisibility $visibility): void
        {
            // Part of the file is there when the write fails.
            Storage::disk($location->disk)->put($location->path, 'part');
            $this->written[] = $location->path;

            throw new RuntimeException('The disk filled up.');
        }

        public function storeNormalized(NormalizedImage $image, MediaStoreRequest $request, ?string $originalFilename): StoredMedia
        {
            return $this->real->storeNormalized($image, $request, $originalFilename);
        }

        public function putContents(MediaLocation $location, string $contents, MediaVisibility $visibility): void
        {
            $this->real->putContents($location, $contents, $visibility);
        }

        public function copyToFile(MediaLocation $location, string $localPath): void
        {
            $this->real->copyToFile($location, $localPath);
        }

        public function exists(MediaLocation $location): bool
        {
            return $this->real->exists($location);
        }

        public function size(MediaLocation $location): int
        {
            return $this->real->size($location);
        }

        public function readStream(MediaLocation $location)
        {
            return $this->real->readStream($location);
        }

        public function delete(MediaLocation $location): void
        {
            $this->real->delete($location);
        }

        public function deleteIfExists(MediaLocation $location): void
        {
            $this->real->deleteIfExists($location);
        }

        public function allFiles(string $disk, string $directory): array
        {
            return $this->real->allFiles($disk, $directory);
        }

        public function lastModified(MediaLocation $location): int
        {
            return $this->real->lastModified($location);
        }
    };
    app()->instance(MediaStorage::class, $storage);

    expect(fn () => app(VideoUploadStorer::class)->store(fakeVideoUpload(), 1))->toThrow(RuntimeException::class, 'The disk filled up.');
    expect($storage->written)->toHaveCount(1)
        ->and(Storage::disk('local')->exists($storage->written[0]))->toBeFalse();
});
