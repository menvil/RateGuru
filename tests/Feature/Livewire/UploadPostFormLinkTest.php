<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Enums\MediaStatus;
use App\Enums\PostStatus;
use App\Jobs\ImportVideoAssetJob;
use App\Livewire\Feed\UploadPostForm;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * The upload form's link: a picture's, a video's, or a post's on another
 * site, from which its video, GIF or photo is taken.
 */

beforeEach(function () {
    bindFakeHostResolver([
        'x.com' => ['104.244.42.1'],
        'cdn.syndication.twimg.com' => ['151.101.0.157'],
        'pbs.twimg.com' => ['151.101.64.159'],
        'video.twimg.com' => ['151.101.128.158'],
        'videos.example' => ['93.184.216.40'],
    ]);
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
});

/** X's answer for a post that leads with a video, or with a photo. */
function xPostCarrying(string $type): string
{
    $media = $type === 'video'
        ? ['type' => 'video', 'indices' => [0, 0], 'media_url_https' => 'https://pbs.twimg.com/ext_tw_video_thumb/1/pu/img/thumb.jpg', 'video_info' => ['variants' => [
            ['content_type' => 'video/mp4', 'bitrate' => 2176000, 'url' => 'https://video.twimg.com/ext_tw_video/1/pu/vid/avc1/720x1280/b.mp4'],
        ]]]
        : ['type' => 'photo', 'indices' => [0, 0], 'media_url_https' => 'https://pbs.twimg.com/media/Photo1.jpg'];

    return json_encode(['__typename' => 'Tweet', 'id_str' => '7', 'text' => 'A post', 'user' => ['name' => 'Someone', 'screen_name' => 'someone'], 'mediaDetails' => [$media]], JSON_THROW_ON_ERROR);
}

it('takes the video a post on X leads with: a draft that downloads it, its link kept as the source', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xPostCarrying('video'), 200)]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'From X')
        ->set('importedImageUrl', 'https://x.com/someone/status/7')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null)
        ->assertDispatched('toast', message: __('ui.upload.success_video_processing'));

    $post = Post::query()->sole();
    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->source_url)->toBe('https://x.com/someone/status/7')
        ->and($asset->status)->toBe(MediaStatus::Processing)
        ->and($asset->metadata['import']['url'])->toBe('https://video.twimg.com/ext_tw_video/1/pu/vid/avc1/720x1280/b.mp4');
    Queue::assertPushed(ImportVideoAssetJob::class);
});

it('takes the photo of a post that leads with one, as a picture fetched now', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xPostCarrying('photo'), 200)]);
    $this->mock(StoreImportedImageAction::class, function ($mock): void {
        $mock->shouldReceive('download')->once()->with('https://pbs.twimg.com/media/Photo1?format=jpg&name=large')
            ->andReturn(UploadedFile::fake()->image('photo.jpg', 800, 600));
        $mock->shouldReceive('cleanup')->once();
    });

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A photo from X')
        ->set('importedImageUrl', 'https://x.com/someone/status/7')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null);

    expect(Post::query()->sole()->source_url)->toBe('https://x.com/someone/status/7');
    Queue::assertNotPushed(ImportVideoAssetJob::class);
});

it('takes a direct link to a video file, and fetches nothing while posting', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A clip')
        ->set('importedImageUrl', 'https://videos.example/clip.mp4')
        ->call('submit')
        ->assertHasNoErrors();

    expect(MediaAsset::query()->sole()->metadata['import']['url'])->toBe('https://videos.example/clip.mp4');
    Queue::assertPushed(ImportVideoAssetJob::class);
    Http::assertNothingSent();
});

it('says a link with nothing to take cannot be imported', function () {
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(json_encode(['__typename' => 'Tweet', 'id_str' => '8', 'text' => 'Words only', 'user' => ['name' => 'Someone', 'screen_name' => 'someone'], 'mediaDetails' => []]), 200)]);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Nothing here')
        ->set('importedImageUrl', 'https://x.com/someone/status/8')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.unsupported'));

    expect(Post::query()->count())->toBe(0);
});

it('tells the author what a link may be', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->assertSee(__('ui.upload.media_url_hint'));
});

it('counts links before fetching anything, and keeps a post\'s link without its query as the source', function () {
    config(['rate_limits.link_import.max_attempts' => 1]);
    Http::fake(['cdn.syndication.twimg.com/*' => Http::response(xPostCarrying('video'), 200)]);
    $author = User::factory()->create();

    Livewire::actingAs($author)
        ->test(UploadPostForm::class)
        ->set('title', 'From X')
        ->set('importedImageUrl', 'https://x.com/someone/status/7?s=20&t=tracking')
        ->call('submit')
        ->assertSet('submitError', null);

    expect(Post::query()->sole()->source_url)->toBe('https://x.com/someone/status/7');

    Livewire::actingAs($author)
        ->test(UploadPostForm::class)
        ->set('title', 'Again')
        ->set('importedImageUrl', 'https://x.com/someone/status/9')
        ->call('submit')
        ->assertSet('submitError', __('ui.rate_limit.uploading'));

    Http::assertSentCount(1);
});

it('names no source for a link to a file or a page that is not a social post', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A clip')
        ->set('importedImageUrl', 'https://videos.example/clip.mp4')
        ->call('submit')
        ->assertHasNoErrors();

    expect(Post::query()->sole()->source_url)->toBeNull();
});
