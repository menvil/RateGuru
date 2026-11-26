<?php

use App\Enums\MediaTool;
use App\Enums\MediaType;
use App\Enums\PostStatus;
use App\Exceptions\Import\YtDlpSiteBrokenException;
use App\Filament\Pages\MediaDiagnosticsPage;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Services\Media\Exceptions\MediaToolTimedOutException;
use App\Services\Media\Exceptions\MediaToolUnavailableException;
use App\Services\Media\MediaToolResult;
use App\Services\Media\MediaToolRunner;
use App\Support\Import\YtDlpSiteCheck;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/** An imported video from $url, downloaded already, on a post in the state given. */
function importedVideoFrom(string $url, PostStatus $post = PostStatus::Published): MediaAsset
{
    $asset = MediaAsset::factory()->create([
        'media_type' => MediaType::Video,
        'metadata' => ['import' => ['url' => $url, 'downloaded_at' => now()->toIso8601ZuluString()]],
    ]);
    Post::factory()->create(['image_asset_id' => $asset->id, 'status' => $post]);

    return $asset;
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
            $answer = $this->videoByUrl[$url] ?? false;

            // Something that happens while this page is asked, then its answer.
            if ($answer instanceof Closure) {
                $answer = $answer();
            }

            if ($answer instanceof Throwable) {
                throw $answer;
            }

            return $answer
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

it('reports a site that gives no video two days running — once a day, however often it runs — and forgets it once it works again', function () {
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);

    app(YtDlpSiteCheck::class)->run();
    app(YtDlpSiteCheck::class)->run();
    Exceptions::assertNothingReported();
    expect(YtDlpSiteCheck::results()['Coub']['failures'])->toBe(1);

    $this->travel(1)->day();
    app(YtDlpSiteCheck::class)->run();
    app(YtDlpSiteCheck::class)->run();
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (YtDlpSiteBrokenException $exception): bool => str_contains($exception->getMessage(), 'Coub on 2 days'));

    ytDlpAnswering(['https://coub.com/view/1' => true]);
    expect(app(YtDlpSiteCheck::class)->run()['Coub'])->toMatchArray(['status' => 'ok', 'failures' => 0]);
});

it('asks only about pages yt-dlp itself took, of posts still on the site', function () {
    // A link straight to the file went through the plain download.
    importedVideoFrom('https://media.redgifs.com/Direct.mp4');
    importedVideoFrom('https://www.redgifs.com/watch/hidden', PostStatus::Hidden);
    $live = importedVideoFrom('https://www.redgifs.com/watch/live');
    $runner = ytDlpAnswering([]);

    app(YtDlpSiteCheck::class)->run();

    expect($runner->asked)->toBe(['https://www.redgifs.com/watch/live']);
});

it('finds a site imported from rarely among busier ones, and keeps counting a broken site whose pages are gone', function () {
    importedVideoFrom('https://coub.com/view/rare');
    foreach (range(1, 5) as $n) {
        importedVideoFrom("https://www.redgifs.com/watch/busy{$n}");
    }
    ytDlpAnswering([]);

    app(YtDlpSiteCheck::class)->run();
    expect(YtDlpSiteCheck::results()['Coub'])->toMatchArray(['status' => 'broken', 'failures' => 1]);

    Post::query()->update(['status' => PostStatus::Hidden]);
    $this->travel(1)->day();
    app(YtDlpSiteCheck::class)->run();

    expect(YtDlpSiteCheck::results()['Coub'])->toMatchArray(['status' => 'no_sample', 'failures' => 1]);
});

it('passes over a page that times out for the next, and says once that yt-dlp will not run, counting nothing', function () {
    importedVideoFrom('https://coub.com/view/older');
    importedVideoFrom('https://coub.com/view/slow');
    ytDlpAnswering(['https://coub.com/view/slow' => new MediaToolTimedOutException('slow'), 'https://coub.com/view/older' => true]);
    expect(app(YtDlpSiteCheck::class)->run()['Coub']['status'])->toBe('ok');

    $before = YtDlpSiteCheck::results();
    ytDlpAnswering(['https://coub.com/view/slow' => new MediaToolUnavailableException('missing')]);
    $this->travel(1)->day();

    expect(app(YtDlpSiteCheck::class)->run())->toBe($before)
        ->and(YtDlpSiteCheck::results())->toBe($before);
    Exceptions::assertReported(fn (YtDlpSiteBrokenException $exception): bool => str_contains($exception->getMessage(), 'could not be run'));
});

it('is scheduled daily, never overlapping, in the background so the minute\'s other commands keep their time', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'import:check-ytdlp-sites'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('20 4 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(30)
        ->and($event->runInBackground)->toBeTrue();
});

it('shows each site\'s last result on Media Diagnostics', function () {
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);
    app(YtDlpSiteCheck::class)->run();
    $this->actingAs(User::factory()->admin()->create());

    Livewire\Livewire::test(MediaDiagnosticsPage::class)
        ->assertSee('yt-dlp sites')
        ->assertSee('Taking nothing: Coub')
        ->assertSee('Failing');
});

it('says on Media Diagnostics when the sites were last found working, or that they were never checked', function () {
    $this->actingAs(User::factory()->admin()->create());
    Livewire\Livewire::test(MediaDiagnosticsPage::class)->assertSee('Not checked');

    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering(['https://coub.com/view/1' => true]);
    app(YtDlpSiteCheck::class)->run();

    Livewire\Livewire::test(MediaDiagnosticsPage::class)->assertSee('1 working')->assertSee('Checked');
});

it('runs one check at a time, however it is started, and the command says so', function () {
    importedVideoFrom('https://coub.com/view/1');
    $runner = ytDlpAnswering([]);
    $held = Cache::store('database')->lock(YtDlpSiteCheck::CACHE_KEY.':running', 60);
    $held->get();

    try {
        expect(app(YtDlpSiteCheck::class)->run())->toBeNull()
            ->and($runner->asked)->toBe([])
            ->and(YtDlpSiteCheck::results())->toBe([]);
        $this->artisan('import:check-ytdlp-sites')->expectsOutputToContain('Another check is running')->assertSuccessful();
    } finally {
        $held->release();
    }

    expect(app(YtDlpSiteCheck::class)->run()['Coub']['status'])->toBe('broken');
});

it('counts only days running: after a day nobody checked, or one it worked, the count starts again', function () {
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 04:20:00'));
    app(YtDlpSiteCheck::class)->run();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 04:20:00'));
    app(YtDlpSiteCheck::class)->run();

    expect(YtDlpSiteCheck::results()['Coub'])->toMatchArray(['status' => 'broken', 'failures' => 1, 'failed_on' => '2026-11-23']);
    Exceptions::assertNothingReported();

    // Working in between, the same day: a failure after it is a new first day.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 04:20:00'));
    ytDlpAnswering(['https://coub.com/view/1' => true]);
    app(YtDlpSiteCheck::class)->run();
    ytDlpAnswering([]);
    app(YtDlpSiteCheck::class)->run();

    expect(YtDlpSiteCheck::results()['Coub'])->toMatchArray(['status' => 'broken', 'failures' => 1]);
    Exceptions::assertNothingReported();
});

it('says nothing of a site it asked when yt-dlp then will not run for the next', function () {
    config(['import.ytdlp.sites' => ['coub.com' => 'Coub', 'redgifs.com' => 'RedGifs']]);
    importedVideoFrom('https://coub.com/view/1');
    importedVideoFrom('https://www.redgifs.com/watch/a');
    ytDlpAnswering(['https://www.redgifs.com/watch/a' => true]);
    app(YtDlpSiteCheck::class)->run();
    $before = YtDlpSiteCheck::results();

    // Coub's second day running is asked first; RedGifs then finds yt-dlp gone.
    $this->travel(1)->day();
    ytDlpAnswering(['https://www.redgifs.com/watch/a' => new MediaToolUnavailableException('missing')]);

    expect(app(YtDlpSiteCheck::class)->run())->toBe($before);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (YtDlpSiteBrokenException $exception): bool => str_contains($exception->getMessage(), 'could not be run'));
});

it('asks a site about its three newest pages at most, newest first', function () {
    foreach (range(1, 5) as $n) {
        importedVideoFrom("https://www.redgifs.com/watch/page{$n}");
    }
    $runner = ytDlpAnswering([]);

    app(YtDlpSiteCheck::class)->run();

    expect($runner->asked)->toBe(['https://www.redgifs.com/watch/page5', 'https://www.redgifs.com/watch/page4', 'https://www.redgifs.com/watch/page3']);
});

it('looks no further back through the imports than its scan limit', function () {
    config(['import.ytdlp.check_scan_limit' => 3]);
    importedVideoFrom('https://coub.com/view/too-old');
    foreach (range(1, 3) as $n) {
        importedVideoFrom("https://www.redgifs.com/watch/newer{$n}");
    }
    $runner = ytDlpAnswering([]);

    expect(app(YtDlpSiteCheck::class)->run()['Coub']['status'])->toBe('no_sample')
        ->and($runner->asked)->not->toContain('https://coub.com/view/too-old');
});

it('reads the imports in as many queries for many as for few', function () {
    importedVideoFrom('https://www.redgifs.com/watch/a');
    importedVideoFrom('https://coub.com/view/a');
    ytDlpAnswering([]);
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(YtDlpSiteCheck::class)->run();
        // The lock's own queries aside: its store prunes expired locks by
        // lottery, one query more now and then.
        $queries = array_filter(DB::getQueryLog(), fn (array $query): bool => ! str_contains($query['query'], 'cache_locks'));
        $count = count($queries);
        DB::disableQueryLog();

        return $count;
    };
    $few = $queries();

    foreach (range(1, 12) as $n) {
        importedVideoFrom("https://www.redgifs.com/watch/more{$n}");
    }

    expect($queries())->toBe($few);
});

it('names on Media Diagnostics each site with nothing recent to ask about, and how it fared before', function () {
    config(['import.ytdlp.sites' => ['redgifs.com' => 'RedGifs', 'instagram.com' => 'Instagram']]);
    importedVideoFrom('https://www.redgifs.com/watch/a');
    $reel = importedVideoFrom('https://www.instagram.com/reel/A/');
    ytDlpAnswering(['https://www.redgifs.com/watch/a' => true]);
    app(YtDlpSiteCheck::class)->run();
    $this->actingAs(User::factory()->admin()->create());

    Post::query()->where('image_asset_id', $reel->id)->update(['status' => PostStatus::Hidden]);
    $this->travel(1)->day();
    app(YtDlpSiteCheck::class)->run();

    Livewire\Livewire::test(MediaDiagnosticsPage::class)
        ->assertSee('1 working')
        ->assertSee('No recent imports: Instagram (failing before)')
        ->assertSee('Checked');

    Post::query()->update(['status' => PostStatus::Hidden]);
    app(YtDlpSiteCheck::class)->run();

    Livewire\Livewire::test(MediaDiagnosticsPage::class)
        ->assertSee('Nothing to check')
        ->assertSee('No recent imports: RedGifs, Instagram (failing before)');
});

it('asks no page once its time is up, counting and saving nothing, and says so', function () {
    importedVideoFrom('https://coub.com/view/older');
    importedVideoFrom('https://coub.com/view/slow');
    $runner = ytDlpAnswering(['https://coub.com/view/slow' => function (): bool {
        $this->travel(21)->minutes();

        return false;
    }]);

    expect(app(YtDlpSiteCheck::class)->run())->toBe([])
        ->and($runner->asked)->toBe(['https://coub.com/view/slow'])
        ->and(YtDlpSiteCheck::results())->toBe([]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (YtDlpSiteBrokenException $exception): bool => str_contains($exception->getMessage(), 'ran out of time'));
});

it('drops a run whose lock lapsed and another took, leaving that one\'s results and saying nothing', function () {
    config(['import.ytdlp.sites' => ['coub.com' => 'Coub']]);
    importedVideoFrom('https://coub.com/view/1');
    ytDlpAnswering([]);
    app(YtDlpSiteCheck::class)->run();
    $this->travel(1)->day();
    $theirs = YtDlpSiteCheck::results();

    // While this run asks, its lock lapses and another run takes it.
    ytDlpAnswering(['https://coub.com/view/1' => function (): bool {
        $store = Cache::store('database');
        $store->lock(YtDlpSiteCheck::CACHE_KEY.':running')->forceRelease();
        $store->lock(YtDlpSiteCheck::CACHE_KEY.':running', 600)->get();

        return false;
    }]);

    expect(app(YtDlpSiteCheck::class)->run())->toBe($theirs)
        ->and(YtDlpSiteCheck::results())->toBe($theirs);
    Exceptions::assertNothingReported();
});
