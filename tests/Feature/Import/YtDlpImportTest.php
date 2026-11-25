<?php

use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\FinalizePostMediaAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaStatus;
use App\Enums\MediaTool;
use App\Jobs\ImportVideoAssetJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Livewire\Feed\UploadPostForm;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\YtDlp;
use App\Support\Import\YtDlpSites;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A video taken from a site's page with yt-dlp: only for the sites named in
 * import.ytdlp.sites, only through each one's extractor.
 */

beforeEach(function () {
    bindFakeHostResolver(['www.redgifs.com' => ['104.18.0.1'], 'coub.com' => ['104.18.0.2'], 'www.instagram.com' => ['157.240.2.174']]);
    Http::preventStrayRequests();
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
});

/** yt-dlp's JSON for a page with one video. */
function ytDlpVideoJson(array $changes = []): string
{
    return (string) json_encode($changes + [
        '_type' => 'video',
        'extractor_key' => 'RedGifs',
        'title' => 'A loop',
        'duration' => 12.4,
        'filesize_approx' => 4_000_000,
        'formats' => [['format_id' => 'hd', 'vcodec' => 'avc1', 'ext' => 'mp4']],
    ]);
}

/**
 * A runner standing in for yt-dlp and ffprobe: yt-dlp "downloads" a file to
 * the place its -o names; ffprobe answers as for an acceptable clip.
 */
function ytDlpStandIn(?string $probeJson = null, bool $downloads = true): object
{
    $runner = new class($probeJson, $downloads) implements MediaToolRunner
    {
        /** @var list<array{tool: MediaTool, arguments: list<string>}> */
        public array $calls = [];

        public function __construct(private ?string $probeJson, private bool $downloads) {}

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];

            if ($tool === MediaTool::YtDlp && in_array('--dump-single-json', $arguments, true)) {
                return new MediaToolResult($this->probeJson === null ? 1 : 0, (string) $this->probeJson, '');
            }

            if ($tool === MediaTool::YtDlp) {
                $template = $arguments[array_search('-o', $arguments, true) + 1];

                if ($this->downloads) {
                    file_put_contents(str_replace('%(ext)s', 'mp4', $template), 'the video bytes');
                }

                return new MediaToolResult(0, '', '');
            }

            return acceptableFfprobeResult();
        }
    };

    app()->instance(MediaToolRunner::class, $runner);

    return $runner;
}

it('gives yt-dlp only links to its sites, over https, and only their own extractor', function () {
    $sites = app(YtDlpSites::class);

    expect($sites->extractorFor('https://www.redgifs.com/watch/aloop'))->toBe('RedGifs')
        ->and($sites->extractorFor('https://coub.com/view/1'))->toBe('Coub')
        ->and($sites->extractorFor('http://www.redgifs.com/watch/aloop'))->toBeNull()
        ->and($sites->extractorFor('https://evilredgifs.com/watch/aloop'))->toBeNull()
        ->and($sites->extractorFor('https://example.com/video'))->toBeNull();

    $runner = ytDlpStandIn(ytDlpVideoJson());
    $probe = app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop');

    expect($probe->durationSeconds)->toBe(13)
        ->and($probe->approximateBytes)->toBe(4_000_000)
        ->and($runner->calls[0]['arguments'])->toBe([
            '--ignore-config', '--no-plugin-dirs', '--no-cache-dir',
            '--use-extractors', 'RedGifs',
            '--no-playlist', '--socket-timeout', '15',
            '--dump-single-json', '--skip-download',
            '--', 'https://www.redgifs.com/watch/aloop',
        ]);

    // Another site's link never reaches yt-dlp at all.
    expect(app(YtDlp::class)->probe('https://example.com/video'))->toBeNull()
        ->and($runner->calls)->toHaveCount(1);
});

it('finds no video on a page with none — a photo post — or when yt-dlp fails', function () {
    ytDlpStandIn(ytDlpVideoJson(['vcodec' => 'none', 'formats' => [['vcodec' => 'none']]]));
    expect(app(YtDlp::class)->probe('https://www.instagram.com/p/photo/'))->toBeNull();

    ytDlpStandIn(null);
    expect(app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop'))->toBeNull();
});

it('takes a video from a page on one of yt-dlp\'s sites as a draft that downloads it', function () {
    ytDlpStandIn(ytDlpVideoJson());

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'From RedGifs')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop?ref=share')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null);

    $post = Post::query()->sole();

    expect($post->source_url)->toBe('https://www.redgifs.com/watch/aloop')
        ->and(MediaAsset::query()->findOrFail($post->image_asset_id)->metadata['import']['url'])->toBe('https://www.redgifs.com/watch/aloop?ref=share');
    Queue::assertPushed(ImportVideoAssetJob::class);
});

it('says a page\'s video is too long before anything is downloaded', function () {
    ytDlpStandIn(ytDlpVideoJson(['duration' => 300]));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Too long')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/long')
        ->call('submit')
        ->assertSet('submitError', __('ui.upload.error_video_too_long', ['seconds' => 90]));

    expect(Post::query()->count())->toBe(0);
});

it('downloads a page\'s video through yt-dlp within the upload limits, then hands it on', function () {
    $runner = ytDlpStandIn();
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'From Coub', videoUrl: 'https://coub.com/view/1'));

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    $download = collect($runner->calls)->first(fn (array $call): bool => $call['tool'] === MediaTool::YtDlp)['arguments'];
    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($download)->toContain('--use-extractors', 'Coub', '--max-filesize', '100M', '--match-filters', 'duration<=90', '--merge-output-format', 'mp4', '--no-playlist')
        ->and(array_slice($download, -2))->toBe(['--', 'https://coub.com/view/1'])
        ->and($asset->metadata['import'])->toHaveKey('downloaded_at');
    Storage::disk('local')->assertExists($asset->path);
    Queue::assertPushed(ProcessVideoAssetJob::class);
    Http::assertNothingSent();
});

it('fails the post\'s media when yt-dlp takes nothing from the page', function () {
    ytDlpStandIn(downloads: false);
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'Gone', videoUrl: 'https://coub.com/view/2'));

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    expect(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Failed);
    Queue::assertNotPushed(ProcessVideoAssetJob::class);
});
