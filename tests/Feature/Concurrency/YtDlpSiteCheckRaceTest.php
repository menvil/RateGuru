<?php

use App\Support\Import\YtDlpSiteCheck;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\File;

/*
 * The daily yt-dlp site check against real concurrency, on PostgreSQL: each
 * run is a process of its own with its own connection. A run whose lock
 * lapses while it saves — paused after its check, as a slow or stopped
 * process is — finishes first: the next run, taking the lapsed lock, waits
 * until those results are committed and reads them, so a site on its second
 * day running is reported once, not by both.
 */

const SITE_CHECK_RACE_SKIP = 'PostgreSQL only: the runs find each other through pg_blocking_pids(), and SQLite runs the suite in memory, where a second process cannot join at all. The order of the write and the check is asserted on every engine by YtDlpSiteCheckTest. PostgreSQL is the primary runtime (docs/architecture/database-support.md).';

/**
 * One process of the race: `seed` commits a Coub import and yesterday's
 * failure; `a` runs the check, its lock lapsing two seconds after it took it,
 * and waits with its results written and its lock's row held until `b` is
 * seen waiting behind it; `b` runs the check once `a` holds and its lock has
 * lapsed. Each says what it ran and how many sites it reported.
 *
 * @return array{process: resource, pipes: array<int, resource>}
 */
function siteCheckRaceCompetitor(string $directory, string $label): array
{
    $script = <<<'PHP'
        <?php
        [$directory, $base, $label] = [$argv[1], $argv[2], $argv[3]];
        require $base.'/vendor/autoload.php';
        $app = require_once $base.'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        config([
            'import.ytdlp.sites' => ['coub.com' => 'Coub'],
            'queue.connections.null' => ['driver' => 'null'],
            'queue.default' => 'null',
        ]);

        if ($label === 'seed') {
            $user = App\Models\User::factory()->create();
            $asset = App\Models\MediaAsset::factory()->create([
                'media_type' => App\Enums\MediaType::Video,
                'metadata' => ['import' => ['url' => 'https://coub.com/view/race', 'downloaded_at' => now()->toIso8601ZuluString()]],
            ]);
            $post = App\Models\Post::factory()->create(['user_id' => $user->id, 'image_asset_id' => $asset->id, 'status' => App\Enums\PostStatus::Published, 'published_at' => now()]);
            // Coub took nothing yesterday: a failure today is its second day running, the one reported.
            Illuminate\Support\Facades\Cache::store('database')->forever(App\Support\Import\YtDlpSiteCheck::CACHE_KEY, [
                'Coub' => ['status' => 'broken', 'checked_at' => now()->subDay()->toIso8601ZuluString(), 'failures' => 1, 'failed_on' => now()->subDay()->toDateString()],
            ]);
            echo json_encode(['user' => $user->id, 'asset' => $asset->id, 'post' => $post->id]);
            exit(0);
        }

        // yt-dlp taking nothing from any page.
        $app->instance(App\Services\Media\MediaToolRunner::class, new class implements App\Services\Media\MediaToolRunner
        {
            public function run(App\Enums\MediaTool $tool, array $arguments, int $timeoutSeconds): App\Services\Media\MediaToolResult
            {
                return new App\Services\Media\MediaToolResult(1, '', 'ERROR: Unable to extract');
            }
        });
        Illuminate\Support\Facades\Exceptions::fake();

        $db = Illuminate\Support\Facades\DB::connection();
        $peer = $label === 'a' ? 'b' : 'a';
        $pid = (int) $db->selectOne('select pg_backend_pid() as pid')->pid;
        file_put_contents("{$directory}/pid-{$label}.part", (string) $pid);
        rename("{$directory}/pid-{$label}.part", "{$directory}/pid-{$label}");

        $await = static function (Closure $arrived, string $failure): void {
            $deadline = microtime(true) + 20;

            while (microtime(true) < $deadline) {
                if ($arrived()) {
                    return;
                }

                usleep(1000);
            }

            throw new RuntimeException($failure);
        };

        $peerPid = static function () use ($await, $directory, $peer): int {
            $await(fn (): bool => is_file("{$directory}/pid-{$peer}"), 'the other run never started');

            return (int) file_get_contents("{$directory}/pid-{$peer}");
        };

        if ($label === 'a') {
            $shortened = false;

            $db->listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$shortened, $db, $directory, $await, $peerPid, $pid): void {
                $sql = strtolower($query->sql);

                // Its lock taken: it lapses a few seconds from now, while this run saves.
                if (! $shortened && str_starts_with($sql, 'insert into "cache_locks"')) {
                    $shortened = true;
                    $expires = time() + 3;
                    $db->table('cache_locks')->where('key', 'like', '%'.App\Support\Import\YtDlpSiteCheck::LOCK_NAME)->update(['expiration' => $expires]);
                    file_put_contents("{$directory}/expires", (string) $expires);

                    return;
                }

                // Its results written and its lock's row held: it waits here,
                // its lock lapsing, until b is seen waiting behind it.
                if (str_contains($sql, 'cache_locks') && str_contains($sql, 'for update')) {
                    touch("{$directory}/holding-a");
                    $other = $peerPid();
                    $await(
                        fn (): bool => (bool) $db->selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$pid, $other])->blocked,
                        'b never waited for a',
                    );
                }
            });
        }

        if ($label === 'b') {
            $await(fn (): bool => is_file("{$directory}/holding-a"), 'a never reached its check');
            $expires = (int) file_get_contents("{$directory}/expires");
            $await(fn (): bool => time() > $expires, "a's lock never lapsed");
        }

        try {
            $results = app(App\Support\Import\YtDlpSiteCheck::class)->run();
            $reported = array_filter(
                Illuminate\Support\Facades\Exceptions::reported(),
                fn (Throwable $exception): bool => $exception instanceof App\Exceptions\Import\YtDlpSiteBrokenException,
            );

            echo json_encode(['ran' => $results !== null, 'reported' => count($reported), 'failures' => $results['Coub']['failures'] ?? null]);
            exit(0);
        } catch (Throwable $e) {
            echo 'failed: '.get_class($e).': '.$e->getMessage();
            exit(1);
        }
        PHP;

    $scriptPath = "{$directory}/competitor-{$label}.php";
    file_put_contents($scriptPath, $script);

    $process = proc_open(
        [PHP_BINARY, $scriptPath, $directory, base_path(), $label],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        base_path(),
        racingEnvironment(),
    );

    expect($process)->not->toBeFalse("the {$label} run must start");

    return ['process' => $process, 'pipes' => $pipes];
}

/** Removes what the race committed, for real. */
function forgetSiteCheckRace(ConnectionInterface $db, ?array $seeded, string $directory): void
{
    $db->table('cache')->where('key', 'like', '%'.YtDlpSiteCheck::CACHE_KEY)->delete();
    $db->table('cache_locks')->where('key', 'like', '%'.YtDlpSiteCheck::LOCK_NAME)->delete();

    if ($seeded !== null) {
        $db->table('posts')->where('id', $seeded['post'])->delete();
        $db->table('media_assets')->where('id', $seeded['asset'])->delete();
        $db->table('users')->where('id', $seeded['user'])->delete();
    }

    File::deleteDirectory($directory);
}

it('lets a run whose lock lapses while it saves finish first: the next waits for its results, and the site is reported once', function () {
    $db = committedConnection();
    $directory = raceDirectory('ytdlp-site-check');
    $seeded = null;

    try {
        ['results' => [$seed]] = finishedRace([siteCheckRaceCompetitor($directory, 'seed')]);
        expect($seed['status'])->toBe(0, $seed['output'].' '.$seed['stderr']);
        $seeded = json_decode($seed['output'], true, flags: JSON_THROW_ON_ERROR);

        ['results' => $results, 'report' => $report] = finishedRace([
            siteCheckRaceCompetitor($directory, 'a'),
            siteCheckRaceCompetitor($directory, 'b'),
        ]);

        expect($results[0]['status'])->toBe(0, $report)
            ->and($results[1]['status'])->toBe(0, $report);

        [$a, $b] = array_map(fn (array $result): array => json_decode($result['output'], true, flags: JSON_THROW_ON_ERROR), $results);

        // a reported Coub's second day running; b, reading a's results, did not again.
        expect($a)->toMatchArray(['ran' => true, 'reported' => 1, 'failures' => 2])
            ->and($b)->toMatchArray(['ran' => true, 'reported' => 0, 'failures' => 2]);
    } finally {
        forgetSiteCheckRace($db, $seeded, $directory);
    }
})->skip(fn (): bool => config('database.default') !== 'pgsql', SITE_CHECK_RACE_SKIP);
