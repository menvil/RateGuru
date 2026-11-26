<?php

use App\Actions\Import\StoreImportedImageAction;
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
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Support\Import\SafeImportHttpClient;
use App\Support\Import\YtDlp;
use App\Support\Import\YtDlpIsolation;
use App\Support\Import\YtDlpSites;
use App\Support\Media\VideoUploadStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
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
        ->and($sites->extractorFor('https://www.instagram.com/reel/ABC123/'))->toBe('Instagram')
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

    expect($download)->toContain('--use-extractors', 'RedGifs', '--max-filesize', '100M', '--match-filters', 'duration<=90', '--merge-output-format', 'mp4', '--no-playlist')
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

it('refuses a page\'s video whose length the site does not say, and leaves a video file\'s link to the plain download', function () {
    $runner = ytDlpStandIn(ytDlpVideoJson(['duration' => null]));

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'No length')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/unknown')
        ->call('submit')
        ->assertSet('submitError', __('ui.upload.error_invalid_video'));

    $runner->calls = [];

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'A file on the site')
        ->set('importedImageUrl', 'https://media.redgifs.com/AFile.mp4')
        ->call('submit')
        ->assertHasNoErrors();

    expect($runner->calls)->toBe([])
        ->and(MediaAsset::query()->sole()->metadata['import']['url'])->toBe('https://media.redgifs.com/AFile.mp4');
});

it('takes a page\'s video whose codec yt-dlp was not told — null, or none at all — and refuses only an explicit none', function () {
    ytDlpStandIn(ytDlpVideoJson(['formats' => [['format_id' => 'html5', 'vcodec' => null, 'ext' => 'mp4']]]));
    expect(app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop'))->not->toBeNull();

    ytDlpStandIn(ytDlpVideoJson(['formats' => [['format_id' => 'img', 'vcodec' => 'none', 'acodec' => 'none']]]));
    expect(app(YtDlp::class)->probe('https://www.redgifs.com/watch/aloop'))->toBeNull();
});

it('says a video-only site\'s page gave nothing, rather than posting its poster, and takes an Instagram photo post\'s photo', function () {
    ytDlpStandIn(null);

    Livewire::actingAs(User::factory()->create())
        ->test(UploadPostForm::class)
        ->set('title', 'Broken extractor')
        ->set('importedImageUrl', 'https://www.redgifs.com/watch/aloop')
        ->call('submit')
        ->assertSet('submitError', __('import.errors.fetch_failed'));

    expect(Post::query()->count())->toBe(0);

    // Instagram: no video on a photo post, so its photo is taken instead.
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
