<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Posts\CreatePostAction;
use App\Actions\Posts\FinalizePostMediaAction;
use App\Data\Posts\CreatePostData;
use App\Enums\MediaStatus;
use App\Enums\MediaTool;
use App\Enums\PostStatus;
use App\Exceptions\Import\ImportFetchException;
use App\Jobs\ImportVideoAssetJob;
use App\Jobs\ProcessVideoAssetJob;
use App\Livewire\Feed\UploadPostForm;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\YtDlp;
use App\Support\Import\YtDlpIsolation;
use App\Support\Import\YtDlpProbeException;
use App\Support\Import\YtDlpSites;
use App\Support\Media\VideoUploadStorer;
use App\Support\Settings\ProjectSettingsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * A video taken from a site's page with yt-dlp: only for the sites named in
 * import.ytdlp.sites, only through each one's extractor.
 */

beforeEach(function () {
    bindFakeHostResolver(['www.redgifs.com' => ['104.18.0.1'], 'media.redgifs.com' => ['104.18.0.3'], 'coub.com' => ['104.18.0.2'], 'www.instagram.com' => ['157.240.2.174']]);
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
        // As RedGifs gives them: MP4s with no codec named at all.
        'formats' => [['format_id' => 'sd', 'ext' => 'mp4', 'url' => 'https://media.redgifs.com/a-mobile.mp4'], ['format_id' => 'hd', 'ext' => 'mp4', 'url' => 'https://media.redgifs.com/a.mp4']],
    ]);
}

/**
 * A runner standing in for yt-dlp and ffprobe: yt-dlp "downloads" a file to
 * the place its -o names; ffprobe answers as for an acceptable clip.
 */
function ytDlpStandIn(?string $probeJson = null, bool $downloads = true, string $probeError = 'ERROR: [Instagram] ABC123: There is no video in this post', int $downloadExit = 0, string $downloadError = ''): object
{
    $runner = new class($probeJson, $downloads, $probeError, $downloadExit, $downloadError) implements MediaToolRunner
    {
        /** @var list<array{tool: MediaTool, arguments: list<string>}> */
        public array $calls = [];

        public function __construct(private ?string $probeJson, private bool $downloads, private string $probeError, private int $downloadExit, private string $downloadError) {}

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $this->calls[] = ['tool' => $tool, 'arguments' => $arguments];

            if ($tool === MediaTool::YtDlp && in_array('--dump-single-json', $arguments, true)) {
                // No JSON: yt-dlp failing, by default as it does for a post
                // with no video in it.
                return new MediaToolResult($this->probeJson === null ? 1 : 0, (string) $this->probeJson, $this->probeJson === null ? $this->probeError : '');
            }

            if ($tool === MediaTool::YtDlp) {
                $template = $arguments[array_search('-o', $arguments, true) + 1];

                if ($this->downloads && $this->downloadExit === 0) {
                    file_put_contents(str_replace('%(ext)s', 'mp4', $template), 'the video bytes');
                }

                return new MediaToolResult($this->downloadExit, '', $this->downloadError);
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
        ->and($sites->extractorFor('https://www.instagram.com/reel/ABC123/'))->toBe('Instagram')
        // YouTube's videos and Shorts, on its subdomains too; a youtu.be link not yet.
        ->and($sites->extractorFor('https://www.youtube.com/shorts/JcsN3h05J7k'))->toBe('Youtube')
        ->and($sites->extractorFor('https://m.youtube.com/watch?v=JcsN3h05J7k'))->toBe('Youtube')
        ->and($sites->extractorFor('https://youtu.be/JcsN3h05J7k'))->toBeNull()
        // Not on the list, whatever yt-dlp could do there.
        ->and($sites->extractorFor('https://coub.com/view/1'))->toBeNull()
        ->and($sites->extractorFor('https://www.youporn.com/watch/1/'))->toBeNull()
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
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'From RedGifs', videoUrl: 'https://www.redgifs.com/watch/aloop'));

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    $download = collect($runner->calls)->first(fn (array $call): bool => $call['tool'] === MediaTool::YtDlp)['arguments'];
    $asset = MediaAsset::query()->findOrFail($post->image_asset_id);

    expect($download)->toContain('--use-extractors', 'RedGifs', '--max-filesize', '100M', '--match-filters', 'duration<=?90', '--merge-output-format', 'mp4', '--no-playlist')
        ->and(array_slice($download, -2))->toBe(['--', 'https://www.redgifs.com/watch/aloop'])
        ->and($asset->metadata['import'])->toHaveKey('downloaded_at');
    Storage::disk('local')->assertExists($asset->path);
    Queue::assertPushed(ProcessVideoAssetJob::class);
    Http::assertNothingSent();
});

it('fails the post\'s media when yt-dlp takes nothing from the page', function () {
    ytDlpStandIn(downloads: false);
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'Gone', videoUrl: 'https://www.redgifs.com/watch/gone'));

    (new ImportVideoAssetJob((int) $post->image_asset_id))->handle(app(SafeImportHttpClient::class), app(VideoUploadStorer::class), app(FinalizePostMediaAction::class));

    expect(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Failed);
    Queue::assertNotPushed(ProcessVideoAssetJob::class);
});

it('holds the host to one probe per author and a few at once, while the author waits', function () {
    ytDlpStandIn(ytDlpVideoJson());
    $author = User::factory()->create();
    $held = Cache::store('database')->getStore()->lock('ytdlp-probe:user:'.$author->id, 30);
    $held->get();

    try {
        Livewire::actingAs($author)
            ->test(UploadPostForm::class)
            ->set('title', 'A second at once')
            ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
            ->call('submit')
            ->assertSet('submitError', __('ui.rate_limit.uploading'));
    } finally {
        $held->release();
    }

    expect(Post::query()->count())->toBe(0);
});

it('takes a page\'s video whose length the site does not say, holding it to the limit once downloaded, and leaves a video file\'s link to the plain download', function () {
    // As an Instagram reel read from its page: no length given.
    $runner = ytDlpStandIn(ytDlpVideoJson(['duration' => null]));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'No length')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/unknown')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null);

    $post = Post::query()->where('title', 'No length')->sole();
    app()->call([new ImportVideoAssetJob((int) $post->image_asset_id), 'handle']);
    $download = collect($runner->calls)->first(fn (array $call): bool => $call['tool'] === MediaTool::YtDlp && in_array('-o', $call['arguments'], true))['arguments'];

    // The download passes a length it does not know; processing holds the file to the limit.
    expect($download)->toContain('--match-filters', 'duration<=?'.config('uploads.videos.max_duration_seconds'));

    $runner->calls = [];

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A file on the site')
        ->set('importedImageUrl', 'https://media.redgifs.com/AFile.mp4')
        ->call('submit')
        ->assertHasNoErrors();

    expect($runner->calls)->toBe([])
        ->and(MediaAsset::query()->latest('id')->firstOrFail()->metadata['import']['url'])->toBe('https://media.redgifs.com/AFile.mp4');
});

it('takes a page\'s video whose codec yt-dlp was not told — null, or none at all — and refuses only an explicit none', function () {
    ytDlpStandIn(ytDlpVideoJson(['formats' => [['format_id' => 'html5', 'vcodec' => null, 'ext' => 'mp4']]]));
    expect(app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop'))->not->toBeNull();

    ytDlpStandIn(ytDlpVideoJson(['formats' => [['format_id' => 'img', 'vcodec' => 'none', 'acodec' => 'none']]]));
    expect(app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop'))->toBeNull();
});

it('refuses a page of a site yt-dlp knows but could not read, rather than posting its poster, and takes a photo post\'s photo', function () {
    ytDlpStandIn(null, probeError: 'ERROR: [RedGifs] aloop: Unable to extract video data; please report this issue');

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Broken extractor')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.fetch_failed'));

    expect(Post::query()->count())->toBe(0);

    // A photo post: yt-dlp says there is no video in it, so its photo is taken instead.
    ytDlpStandIn(null);
    Http::fake(['www.instagram.com/*' => Http::response('<html><img class="EmbeddedMediaImage" src="https://www.instagram.com/photo.jpg" srcset="https://www.instagram.com/photo.jpg 1080w"></html>', 200, ['Content-Type' => 'text/html'])]);
    $this->mock(StoreImportedImageAction::class, function ($mock): void {
        $mock->shouldReceive('download')->once()->andReturn(UploadedFile::fake()->image('photo.jpg', 800, 600));
        $mock->shouldReceive('cleanup')->once();
    });

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'An Instagram photo')
        ->set('importedImageUrl', 'https://www.instagram.com/p/ABC123/')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('submitError', null);

    Queue::assertNotPushed(ImportVideoAssetJob::class);
});

it('runs yt-dlp as its target\'s own account through sudo where the host has it and its firewall is loaded, as the worker where it has none', function () {
    $worker = posix_getpwuid(posix_geteuid())['name'];
    $account = fn (array &$asked): Closure => function (string $name) use (&$asked): bool {
        $asked[] = $name;

        return true;
    };
    $asked = [];

    expect((new YtDlpIsolation($account($asked), armed: fn (): bool => true))->command('/usr/local/bin/yt-dlp'))
        ->toBe(['/usr/bin/sudo', '-n', '-u', "{$worker}-fetch", '--', '/usr/local/bin/yt-dlp'])
        // The worker's own name and the suffix: one account per target.
        ->and($asked)->toBe(["{$worker}-fetch"])
        ->and((new YtDlpIsolation(fn (): bool => false))->command('/usr/local/bin/yt-dlp'))->toBe(['/usr/local/bin/yt-dlp']);

    // The account there, its firewall not: never run as it without the table.
    expect(fn () => (new YtDlpIsolation(fn (): bool => true, armed: fn (): bool => false))->command('/usr/local/bin/yt-dlp'))
        ->toThrow(MediaToolUnavailableException::class);
});

it('opens the download directory to the account for the run, closes it after, and takes only a plain file it wrote as a copy of its own', function () {
    $worker = posix_getpwuid(posix_geteuid())['name'];
    $calls = [];
    $isolation = new YtDlpIsolation(fn (): bool => true, function (array $arguments) use (&$calls): bool {
        $calls[] = $arguments;

        return true;
    }, fn (): bool => true);
    $directory = makeScratchDir('ytdlp-run').'/download-abc.d';
    mkdir($directory, 0o700);

    try {
        $isolation->share($directory);
        file_put_contents($directory.'/download.mp4', 'the video');
        $taken = $isolation->take($directory, $directory.'/download.mp4');

        expect($calls)->toBe([
            ['-m', "u:{$worker}-fetch:x", dirname($directory)],
            ['-m', "u:{$worker}-fetch:rwx,d:u:{$worker}-fetch:rwx,d:u:{$worker}:rwx", $directory],
            ['-x', "u:{$worker}-fetch,d:u:{$worker}-fetch", $directory],
        ])
            ->and($taken)->toBe($directory.'/taken.mp4')
            ->and(file_get_contents($taken))->toBe('the video');

        // A link, or a file with a second name, is never taken.
        symlink('/etc/hosts', $directory.'/linked.mp4');
        link($directory.'/download.mp4', $directory.'/hard.mp4');
        expect($isolation->take($directory, $directory.'/linked.mp4'))->toBeNull()
            ->and($isolation->take($directory, $directory.'/download.mp4'))->toBeNull();
    } finally {
        removeScratchDir(dirname($directory));
    }

    $refusing = new YtDlpIsolation(fn (): bool => true, fn (): bool => false, fn (): bool => true);
    $there = makeScratchDir('ytdlp-refused');
    expect(fn () => $refusing->share('/tmp/x/download.d'))->toThrow(RuntimeException::class)
        ->and(fn () => $refusing->take($there, null))->toThrow(RuntimeException::class);
    removeScratchDir($there);

    // Without the account, nothing is opened or closed, and the file is taken as it is.
    $calls = [];
    $none = new YtDlpIsolation(fn (): bool => false, function (array $arguments) use (&$calls): bool {
        $calls[] = $arguments;

        return true;
    });
    $none->share('/tmp/x/download.d');
    expect($none->take('/tmp/x/download.d', '/tmp/x/download.d/download.mp4'))->toBe('/tmp/x/download.d/download.mp4')
        ->and($calls)->toBe([]);
});

it('tells sudo refusing yt-dlp from yt-dlp failing on a page, and from a warning sudo prints before it runs', function () {
    expect(YtDlpIsolation::refused(1, "sudo: a password is required\n"))->toBeTrue()
        ->and(YtDlpIsolation::refused(1, "sudo: user rateguru-staging is not allowed to execute '/bin/true' as rateguru-staging-fetch\n"))->toBeTrue()
        ->and(YtDlpIsolation::refused(1, 'ERROR: [RedGifs] aloop: Unable to extract'))->toBeFalse()
        // A warning, and yt-dlp ran: no refusal.
        ->and(YtDlpIsolation::refused(0, "sudo: unable to resolve host web-1: Name or service not known\n"))->toBeFalse()
        ->and(YtDlpIsolation::refused(1, "sudo: unable to resolve host web-1\nERROR: [RedGifs] Unable to extract\n"))->toBeFalse();
});

it('never runs yt-dlp as the worker on a host prepared for isolation that has no account for it', function () {
    expect(fn () => (new YtDlpIsolation(fn (): bool => false, armed: fn (): bool => true))->command('/usr/local/bin/yt-dlp'))
        ->toThrow(MediaToolUnavailableException::class)
        // Nor opens anything to an account that may not run.
        ->and(fn () => (new YtDlpIsolation(fn (): bool => true, fn (): bool => true, fn (): bool => false))->share('/tmp/x/download.d'))
        ->toThrow(MediaToolUnavailableException::class);
});

it('closes the directory to the account when the run ends in an exception, and never copies a file larger than an upload may be', function () {
    $calls = [];
    $isolation = new YtDlpIsolation(fn (): bool => true, function (array $arguments) use (&$calls): bool {
        $calls[] = $arguments[0];

        return true;
    }, fn (): bool => true);
    app()->instance(YtDlpIsolation::class, $isolation);
    app()->instance(MediaToolRunner::class, new class implements MediaToolRunner
    {
        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            throw MediaToolTimedOutException::after($tool, $timeoutSeconds);
        }
    });
    $directory = makeScratchDir('ytdlp-run').'/download-abc.d';
    mkdir($directory, 0o700);

    try {
        expect(fn () => app(YtDlp::class)->download('https://www.redgifs.com/watch/aloop', $directory))->toThrow(MediaToolTimedOutException::class)
            ->and($calls)->toBe(['-m', '-m', '-x']);

        config(['uploads.videos.max_kilobytes' => 1]);
        file_put_contents($directory.'/download.mp4', str_repeat('x', 2048));
        expect($isolation->take($directory, $directory.'/download.mp4'))->toBeNull();
    } finally {
        removeScratchDir(dirname($directory));
    }
});

it('refuses a link to try later when yt-dlp cannot answer, never taking an Instagram reel for a photo post', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A reel')
        ->set('importedImageUrl', 'https://www.instagram.com/reel/ABC123/');

    app()->instance(MediaToolRunner::class, new class implements MediaToolRunner
    {
        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            throw MediaToolUnavailableException::cannotStart($tool);
        }
    });
    Exceptions::fake();

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A reel')
        ->set('importedImageUrl', 'https://www.instagram.com/reel/ABC123/')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.fetch_failed'));

    expect(Post::query()->count())->toBe(0);
    Http::assertNothingSent();
});

it('refuses a page yt-dlp failed to read, never taking it for a photo post, and goes on with one it says has no video', function () {
    ytDlpStandIn(null, probeError: 'ERROR: [Instagram] ABC123: Unable to extract shared data; please report this issue');
    expect(fn () => app(YtDlp::class)->probe('https://www.instagram.com/reel/ABC123/'))->toThrow(YtDlpProbeException::class);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A reel')
        ->set('importedImageUrl', 'https://www.instagram.com/reel/ABC123/')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.fetch_failed'));
    expect(Post::query()->count())->toBe(0);
    Http::assertNothingSent();

    ytDlpStandIn(null);
    expect(app(YtDlp::class)->probe('https://www.instagram.com/p/ABC123/'))->toBeNull();
});

it('refuses an Instagram carousel with a video among its photos, and takes one of photos alone as a photo post', function () {
    $carousel = fn (array $entries): string => (string) json_encode(['_type' => 'playlist', 'extractor_key' => 'Instagram', 'entries' => $entries]);
    ytDlpStandIn($carousel([['_type' => 'url', 'formats' => [['vcodec' => 'none', 'url' => 'https://x/1.jpg']]], json_decode(ytDlpVideoJson(), true)]));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A carousel')
        ->set('importedImageUrl', 'https://www.instagram.com/p/CAROUSEL/')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.unsupported'));

    ytDlpStandIn($carousel([['formats' => [['vcodec' => 'none', 'url' => 'https://x/1.jpg']]], ['formats' => [['vcodec' => 'none', 'url' => 'https://x/2.jpg']]]]));
    expect(app(YtDlp::class)->probe('https://www.instagram.com/p/CAROUSEL/'))->toBeNull();
});

it('sends a download yt-dlp failed at back to the job to try once more, and gives up on a page with no video at once', function () {
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'From RedGifs', videoUrl: 'https://www.redgifs.com/watch/aloop'));
    ytDlpStandIn(downloadExit: 1, downloadError: 'ERROR: [RedGifs] aloop: HTTP Error 503: Service Unavailable');

    expect(fn () => app()->call([new ImportVideoAssetJob((int) $post->image_asset_id), 'handle']))->toThrow(ImportFetchException::class);
    expect(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Processing);

    ytDlpStandIn(downloadExit: 1, downloadError: 'ERROR: [RedGifs] aloop: There is no video in this post');
    app()->call([new ImportVideoAssetJob((int) $post->image_asset_id), 'handle']);
    expect(MediaAsset::query()->findOrFail($post->image_asset_id)->status)->toBe(MediaStatus::Failed);
});

it('takes a silent video too: its video alone, after every choice with sound', function () {
    $post = app(CreatePostAction::class)->handle(User::factory()->create(), new CreatePostData(title: 'Silent', videoUrl: 'https://www.redgifs.com/watch/silent'));
    $runner = ytDlpStandIn();

    app()->call([new ImportVideoAssetJob((int) $post->image_asset_id), 'handle']);

    $download = collect($runner->calls)->first(fn (array $call): bool => $call['tool'] === MediaTool::YtDlp && in_array('-f', $call['arguments'], true))['arguments'];
    expect($download[array_search('-f', $download, true) + 1])->toEndWith('/bv*[height<=1080]/b');
});

it('never writes its copy through an entry the account left at that name', function () {
    $isolation = new YtDlpIsolation(fn (): bool => true, fn (): bool => true, fn (): bool => true);
    $directory = makeScratchDir('ytdlp-take').'/download-abc.d';
    mkdir($directory, 0o700);
    $victim = dirname($directory).'/victim.txt';
    file_put_contents($victim, 'the worker\'s own file');

    try {
        file_put_contents($directory.'/download.mp4', 'the video');
        symlink($victim, $directory.'/taken.mp4');
        Exceptions::fake();

        expect($isolation->take($directory, $directory.'/download.mp4'))->toBeNull()
            ->and(file_get_contents($victim))->toBe('the worker\'s own file');
    } finally {
        removeScratchDir(dirname($directory));
    }
});

it('takes an answer that names no media for no answer at all, never for a page without a video', function (string $answer) {
    ytDlpStandIn($answer);

    expect(fn () => app(YtDlp::class)->probe('https://www.instagram.com/reel/ABC123/'))->toThrow(YtDlpProbeException::class);
})->with([
    'an empty object' => ['{}'],
    'a carousel listing nothing' => ['{"_type":"playlist","extractor_key":"Instagram"}'],
    'a carousel of no entries' => ['{"_type":"playlist","entries":[]}'],
    'an answer of another kind' => ['{"_type":"url","url":"https://www.instagram.com/p/X/"}'],
    'a video answer with no formats in its list' => ['{"_type":"video","formats":[]}'],
    'a carousel with an empty entry' => ['{"_type":"playlist","entries":[{}]}'],
    'a carousel with one photo and an empty entry' => ['{"_type":"playlist","entries":[{"formats":[{"vcodec":"none","url":"https://x/1.jpg"}]},{}]}'],
    'a carousel with an entry that is no object' => ['{"_type":"playlist","entries":["x"]}'],
]);

it('logs why a page could not be read: yt-dlp\'s last error line, or what was wrong with its answer', function () {
    Log::spy();
    ytDlpStandIn(null, probeError: "WARNING: something\nERROR: [Instagram] ABC123: Unable to extract shared data; please report this issue\n");

    expect(fn () => app(YtDlp::class)->probe('https://www.instagram.com/reel/ABC123/'))->toThrow(YtDlpProbeException::class);

    Log::shouldHaveReceived('warning')->with('YtDlp: a page could not be read.', ['extractor' => 'Instagram', 'why' => 'ERROR: [Instagram] ABC123: Unable to extract shared data; please report this issue']);
});

it('shows a pasted page\'s video before posting — its still and length — and posts it without reading the link again', function () {
    $runner = ytDlpStandIn(ytDlpVideoJson(['thumbnail' => 'https://media.redgifs.com/a-poster.jpg']));
    $probes = fn (): int => collect($runner->calls)->filter(fn (array $call): bool => in_array('--dump-single-json', $call['arguments'], true))->count();

    $form = Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', ' https://www.redgifs.com/watch/aloop ')
        ->assertHasNoErrors()
        ->assertSet('linkPreview', ['video' => true, 'image' => 'https://media.redgifs.com/a-poster.jpg', 'duration' => '0:13'])
        ->assertSeeText(__('ui.upload.link_preview_video').' · 0:13')
        ->assertSeeHtml('src="https://media.redgifs.com/a-poster.jpg"');
    expect($probes())->toBe(1);

    // Read once: the same link again shows the same, and posting it reads nothing more.
    $form->call('previewLink', 'https://www.redgifs.com/watch/aloop')
        ->set('title', 'Previewed')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        ->call('submit')
        ->assertSet('submitError', null);

    expect($probes())->toBe(1)
        ->and(Post::query()->where('title', 'Previewed')->sole()->imageAsset->metadata['import']['url'])->toBe('https://www.redgifs.com/watch/aloop');
});

it('says at once, by the link, why a pasted link cannot be posted, and shows a picture\'s link as the picture', function () {
    ytDlpStandIn(ytDlpVideoJson(['duration' => 9999]));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://www.redgifs.com/watch/long')
        ->assertHasErrors(['importedImageUrl' => __('ui.upload.error_video_too_long', ['seconds' => (int) config('uploads.videos.max_duration_seconds')])])
        ->assertSet('linkPreview', null)
        // A picture's own link needs no reading: it is shown as itself.
        ->call('previewLink', 'https://cdn.example.com/photo.jpg')
        ->assertHasNoErrors()
        ->assertSet('linkPreview', ['video' => false, 'image' => 'https://cdn.example.com/photo.jpg', 'duration' => null])
        // Emptied: nothing shown.
        ->call('previewLink', '')
        ->assertSet('linkPreview', null)
        ->assertSet('previewedLink', '');
});

it('reads nothing for a guest, nor for an account that may not post', function () {
    $runner = ytDlpStandIn(ytDlpVideoJson());

    Livewire::actingAs(User::factory()->unverified()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://www.redgifs.com/watch/aloop')
        ->assertSet('linkPreview', null);

    expect($runner->calls)->toBe([]);
});

it('keeps a YouTube watch link\'s video in the post\'s source, and nothing else of its query', function () {
    bindFakeHostResolver(['www.youtube.com' => ['142.250.0.1']]);
    ytDlpStandIn(ytDlpVideoJson(['extractor_key' => 'Youtube']));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'From YouTube')
        ->set('importedImageUrl', 'https://www.youtube.com/watch?v=jNQXAC9IVRw&t=10s&si=tracking')
        ->call('submit')
        ->assertSet('submitError', null);

    expect(Post::query()->where('title', 'From YouTube')->sole()->source_url)->toBe('https://www.youtube.com/watch?v=jNQXAC9IVRw');
});

it('lets the preview go when the upload form opens again, and posts nothing it read once imports are switched off', function () {
    ytDlpStandIn(ytDlpVideoJson());

    $form = Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://www.redgifs.com/watch/aloop')
        ->assertSet('previewedLink', 'https://www.redgifs.com/watch/aloop')
        ->call('resetUploadForm')
        ->assertSet('linkPreview', null)
        ->assertSet('previewedLink', '')
        ->assertSet('previewedResolution', null)
        ->call('previewLink', 'https://www.redgifs.com/watch/aloop');

    ProjectSettings::factory()->create(['feature_flags' => ['allow_url_imports' => false]]);
    app(ProjectSettingsManager::class)->flush();

    $form->set('title', 'Too late')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.feature_disabled'));

    expect(Post::query()->where('title', 'Too late')->exists())->toBeFalse();
});

it('takes what a preview read once: a submit that fails after it reads the link again, counted, on the next try', function () {
    $runner = ytDlpStandIn(ytDlpVideoJson());
    $probes = fn (): int => collect($runner->calls)->filter(fn (array $call): bool => in_array('--dump-single-json', $call['arguments'], true))->count();

    $form = Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->call('previewLink', 'https://www.redgifs.com/watch/aloop')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        // No title: the submit fails after taking the preview's reading.
        ->call('submit')
        ->assertHasErrors('title')
        ->assertSet('previewedLink', '');
    expect($probes())->toBe(1);

    $form->set('title', 'Second try')->call('submit')->assertSet('submitError', null);

    expect($probes())->toBe(2)
        ->and(Post::query()->where('title', 'Second try')->exists())->toBeTrue();
});

it('gives yt-dlp any other page, with every extractor but the generic one, only where it runs isolated — never a file, a post on X or a blocked host', function () {
    $isolated = fn (bool $armed) => app()->instance(YtDlpIsolation::class, new YtDlpIsolation(fn (): bool => true, fn (): bool => true, fn (): bool => $armed));
    $sites = fn (): YtDlpSites => app(YtDlpSites::class);
    config(['import.ytdlp.blocked_domains' => ['blocked.example']]);

    $isolated(true);
    expect($sites()->extractorFor('https://vimeo.com/123456'))->toBe(YtDlpSites::ANY)
        ->and(YtDlpSites::ANY)->toBe('default,-generic')
        ->and($sites()->extractorFor('https://www.facebook.com/watch/?v=1'))->toBe(YtDlpSites::ANY)
        // A named site keeps its own extractor.
        ->and($sites()->extractorFor('https://www.youtube.com/shorts/JcsN3h05J7k'))->toBe('Youtube')
        // X's own reader takes its video; files go to the plain download.
        ->and($sites()->extractorFor('https://x.com/someone/status/1'))->toBeNull()
        ->and($sites()->extractorFor('https://cdn.example.com/clip.mp4'))->toBeNull()
        ->and($sites()->extractorFor('https://cdn.example.com/photo.jpg'))->toBeNull()
        ->and($sites()->extractorFor('https://www.blocked.example/video/1'))->toBeNull()
        ->and($sites()->extractorFor('http://vimeo.com/123456'))->toBeNull();

    // Not isolated — the firewall not loaded, or no account: the named sites only.
    $isolated(false);
    expect($sites()->extractorFor('https://vimeo.com/123456'))->toBeNull()
        ->and($sites()->extractorFor('https://www.youtube.com/shorts/JcsN3h05J7k'))->toBe('Youtube');

    $isolated(true);
    config(['import.ytdlp.any_site' => false]);
    expect($sites()->extractorFor('https://vimeo.com/123456'))->toBeNull();
});

it('posts any other page\'s video yt-dlp finds', function () {
    app()->instance(YtDlpIsolation::class, new YtDlpIsolation(fn (): bool => true, fn (): bool => true, fn (): bool => true));
    bindFakeHostResolver(['vimeo.com' => ['151.101.0.217']]);

    $runner = ytDlpStandIn(ytDlpVideoJson(['extractor_key' => 'Vimeo']));
    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'From Vimeo')
        ->set('importedImageUrl', 'https://vimeo.com/123456')
        ->call('submit')
        ->assertSet('submitError', null);

    expect(Post::query()->where('title', 'From Vimeo')->sole()->status)->toBe(PostStatus::Draft)
        ->and($runner->calls[0]['arguments'])->toContain('--use-extractors', 'default,-generic');

});

// A page no extractor knows is not refused, not logged — its picture is
// taken — whichever way yt-dlp says so: without the generic extractor, that
// none matched; from one that matched, that it does not support the page.
it('takes the picture of a page no yt-dlp extractor knows, as any link', function (string $diagnostic) {
    app()->instance(YtDlpIsolation::class, new YtDlpIsolation(fn (): bool => true, fn (): bool => true, fn (): bool => true));
    bindFakeHostResolver(['news.example.org' => ['93.184.216.40'], 'cdn.example.org' => ['93.184.216.41']]);
    Log::spy();
    ytDlpStandIn(null, probeError: $diagnostic);
    Http::fake([
        'news.example.org/*' => Http::response('<head><meta property="og:image" content="https://cdn.example.org/story.jpg"></head>', 200, ['Content-Type' => 'text/html']),
    ]);
    $this->mock(StoreImportedImageAction::class, function ($mock): void {
        $mock->shouldReceive('download')->once()->with('https://cdn.example.org/story.jpg')->andReturn(UploadedFile::fake()->image('story.jpg', 800, 600));
        $mock->shouldReceive('cleanup')->once();
    });

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A story')
        ->set('importedImageUrl', 'https://news.example.org/story')
        ->call('submit')
        ->assertSet('submitError', null);

    expect(Post::query()->where('title', 'A story')->exists())->toBeTrue();
    Log::shouldNotHaveReceived('warning');
})->with([
    'no extractor matched' => 'ERROR: No suitable extractor found for URL https://news.example.org/story',
    'the generic extractor\'s answer' => 'ERROR: Unsupported URL: https://news.example.org/story',
]);

it('says a page that did not show its own picture cannot be imported, rather than posting the site\'s logo', function () {
    bindFakeHostResolver(['nsfw.example' => ['93.184.216.50']]);
    Http::fake([
        'nsfw.example/*' => Http::response('<head><meta property="og:url" content="https://nsfw.example/"><meta property="og:image" content="https://nsfw.example/assets/og-image.png"></head>', 200, ['Content-Type' => 'text/html']),
    ]);
    $this->mock(StoreImportedImageAction::class, fn ($mock) => $mock->shouldNotReceive('download'));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A logo')
        ->set('importedImageUrl', 'https://nsfw.example/post/aRLwNBB')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.unsupported'));

    expect(Post::query()->count())->toBe(0);
});

it('tells the author a site refusing this server is to be uploaded from instead, and counts the refusal, unreported', function (string $diagnostic) {
    Exceptions::fake();
    Log::spy();
    ytDlpStandIn(null, probeError: $diagnostic);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Refused')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.site_refusing'));

    expect(Post::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->with('Import: a site refused a link.', Mockery::on(fn (array $context): bool => $context['site'] === 'redgifs.com'));
    Exceptions::assertNothingReported();
})->with([
    'too many requests' => 'ERROR: [RedGifs] aloop: Unable to download JSON metadata: HTTP Error 429: Too Many Requests',
    'forbidden' => 'ERROR: [RedGifs] aloop: Unable to download JSON metadata: HTTP Error 403: Forbidden',
    'a check that it is no robot' => 'ERROR: [youtube] abc: Sign in to confirm you’re not a bot. Use --cookies-from-browser or --cookies for the authentication.',
    'a rate limit' => 'ERROR: [Instagram] ABC: Requested content is not available, rate-limit reached or login required.',
]);
