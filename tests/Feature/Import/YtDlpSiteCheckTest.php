<?php

use App\Enums\MediaTool;
use App\Enums\MediaType;
use App\Exceptions\Import\YtDlpSiteBrokenException;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Support\Import\YtDlpSiteCheck;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;

/** An imported video from $url, downloaded already. */
function importedVideoFrom(string $url): MediaAsset
{
    return MediaAsset::factory()->create([
        'media_type' => MediaType::Video,
        'metadata' => ['import' => ['url' => $url, 'downloaded_at' => now()->toIso8601ZuluString()]],
    ]);
}

/** yt-dlp answering each page with a video, or with none, by URL. */
function ytDlpAnswering(array $videoByUrl): object
{
    $runner = new class($videoByUrl) implements MediaToolRunner
    {
        /** @var list<string> */
        public array $asked = [];

        public function __construct(private array $videoByUrl) {}

        public function run(MediaTool $tool, array $arguments, int $timeoutSeconds): MediaToolResult
        {
            $url = end($arguments);
            $this->asked[] = $url;

            return ($this->videoByUrl[$url] ?? false)
                ? new MediaToolResult(0, (string) json_encode(['_type' => 'video', 'duration' => 10, 'formats' => [['vcodec' => 'avc1']]]), '')
                : new MediaToolResult(1, '', 'ERROR: Unable to extract');
        }
    };

    app()->instance(MediaToolRunner::class, $runner);

    return $runner;
}

beforeEach(function () {
    config(['import.ytdlp.sites' => ['redgifs.com' => 'RedGifs', 'coub.com' => 'Coub', 'youporn.com' => 'YouPorn']]);
    Exceptions::fake();
});

it('asks each site about the pages its videos were last imported from, and keeps every result', function () {
    importedVideoFrom('https://www.redgifs.com/watch/old');
    importedVideoFrom('https://www.redgifs.com/watch/new');
    importedVideoFrom('https://coub.com/view/gone');
    $runner = ytDlpAnswering(['https://www.redgifs.com/watch/new' => true]);

    $results = app(YtDlpSiteCheck::class)->run();

    expect($results['RedGifs']['status'])->toBe('ok')
        ->and($results['Coub']['status'])->toBe('broken')
        ->and($results['YouPorn']['status'])->toBe('no_sample')
        // The newest page first, and no more once one gives a video.
        ->and($runner->asked)->toBe(['https://www.redgifs.com/watch/new', 'https://coub.com/view/gone'])
        ->and(YtDlpSiteCheck::results())->toBe($results);
    Exceptions::assertNothingReported();
});

it('reports a site that gives no video two checks running, and forgets it once it works again', function () {
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);

    app(YtDlpSiteCheck::class)->run();
    Exceptions::assertNothingReported();

    app(YtDlpSiteCheck::class)->run();
    Exceptions::assertReported(fn (YtDlpSiteBrokenException $exception): bool => str_contains($exception->getMessage(), 'Coub'));

    ytDlpAnswering(['https://coub.com/view/1' => true]);
    expect(app(YtDlpSiteCheck::class)->run()['Coub'])->toMatchArray(['status' => 'ok', 'failures' => 0]);
});

it('is scheduled daily, never overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'import:check-ytdlp-sites'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('shows each site\'s last result on Media Diagnostics', function () {
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);
    app(YtDlpSiteCheck::class)->run();
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(MediaDiagnosticsPage::class)
        ->assertSee('yt-dlp sites')
        ->assertSee('Taking nothing: Coub');
});
