<?php

use App\Models\User;
use App\Providers\ObservabilityServiceProvider;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Features\QueueIntegration;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Proves the whole capture path end to end — HTTP, queue and Artisan — against
 * an in-memory transport. No test here opens a socket, and no permanent crash
 * route or deliberately failing production job is left behind: the probe route
 * exists only inside the test's own application instance and the probe job only
 * inside this file.
 */
final class Phase6FailingProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('phase 6 queue probe failure');
    }
}

beforeEach(function (): void {
    config([
        'deployment.target' => 'staging-main',
        'deployment.commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        'sentry.release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'sentry.environment' => 'staging',
    ]);

    (new ObservabilityServiceProvider(app()))->boot();
});

it('reports an unhandled HTTP exception exactly once, with the deployment identity attached', function () {
    Route::middleware('web')->get('/__phase6-exception-probe', function (): never {
        throw new RuntimeException('phase 6 unhandled backend failure');
    });

    $transport = fakeSentryTransport();

    $this->get('/__phase6-exception-probe')->assertStatus(500);

    $errors = $transport->errorEvents();

    expect($errors)->toHaveCount(1, 'an unhandled exception must produce exactly one Sentry event');

    $event = $errors[0];
    $exceptions = $event->getExceptions();

    expect($exceptions)->not->toBeEmpty()
        ->and($exceptions[0]->getType())->toBe(RuntimeException::class)
        ->and($exceptions[0]->getValue())->toBe('phase 6 unhandled backend failure')
        ->and($event->getRelease())->toBe('v0.0.0-20260826-120211-ca7d1c7')
        ->and($event->getEnvironment())->toBe('staging')
        ->and($event->getTags())->toMatchArray([
            'deployment_target' => 'staging-main',
            'commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        ]);
});

it('leaves a missing page out of Sentry', function () {
    // Laravel's own dontReport list is what filters these, upstream of the
    // reportable callback Sentry hangs off — so there is nothing to configure
    // and nothing that could accidentally hide a genuine 5xx.
    $transport = fakeSentryTransport();

    $this->get('/__phase6-no-such-page')->assertStatus(404);

    expect($transport->errorEvents())->toBe([]);
});

it('leaves a validation failure out of Sentry', function () {
    $transport = fakeSentryTransport();

    // The contact form's own validation — a real application path, not a stub.
    $this->post('/contact', [])->assertStatus(302);

    expect($transport->errorEvents())->toBe([]);
});

it('leaves an authentication failure out of Sentry', function () {
    $transport = fakeSentryTransport();

    $this->get('/profile')->assertRedirect('/login');

    expect($transport->errorEvents())->toBe([]);
});

it('leaves an authorization failure out of Sentry', function () {
    // A signed-in user who fails a real Gate — a genuine 403 out of the
    // application's own authorization, not a stub. bootstrap/app.php and
    // config/sentry.php both claim authorization failures never reach Sentry;
    // this is what actually holds them to it.
    $transport = fakeSentryTransport();

    $this->actingAs(User::factory()->create())
        ->get('/admin/media-diagnostics')
        ->assertForbidden();

    expect($transport->errorEvents())->toBe([]);
});

it('reports a failed queue job once, with job, queue and deployment identity', function () {
    // Driven through the real queue worker, not the sync driver: the worker is
    // what reports a job failure, and running it is the only way to prove the
    // SDK does not also capture the same exception a second time.
    config(['queue.default' => 'database']);

    Phase6FailingProbeJob::dispatch()->onQueue('phase6-probe');

    $transport = fakeSentryTransport();

    // With no DSN configured at boot — the correct local and CI default — the
    // SDK boots its queue integration inactive, so no queue instrumentation is
    // registered. Activate that official integration now that a (fake) DSN
    // exists, so what follows exercises the SDK's own queue context rather
    // than anything RateGuru reimplemented.
    app(QueueIntegration::class)->boot();

    Artisan::call('queue:work', [
        '--once' => true,
        '--queue' => 'phase6-probe',
        '--tries' => 1,
    ]);

    $errors = $transport->errorEvents();

    expect($errors)->toHaveCount(1, 'a failed queue job must produce exactly one Sentry event, never two');

    $event = $errors[0];

    expect($event->getExceptions()[0]->getValue())->toBe('phase 6 queue probe failure')
        ->and($event->getRelease())->toBe('v0.0.0-20260826-120211-ca7d1c7')
        ->and($event->getTags())->toMatchArray([
            'deployment_target' => 'staging-main',
            'commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        ]);

    // The job identity comes from the SDK's own queue breadcrumb — the class,
    // the queue and the attempt, and deliberately not the serialized payload.
    $queueCrumbs = array_values(array_filter(
        $event->getBreadcrumbs(),
        static fn ($crumb): bool => $crumb->getCategory() === 'queue.job',
    ));

    expect($queueCrumbs)->not->toBeEmpty();

    $metadata = $queueCrumbs[0]->getMetadata();

    expect($metadata['resolved'] ?? $metadata['job'])->toBe(Phase6FailingProbeJob::class)
        ->and($metadata['queue'])->toBe('phase6-probe')
        ->and($metadata['attempts'])->toBe(1);

    // The failed job still lands in failed_jobs: observability changed nothing
    // about how the queue itself behaves.
    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('reports an unhandled Artisan failure through the real console kernel', function () {
    // Artisan::call() rethrows, which is not what `php artisan` does. Driving
    // the console kernel is the actual production path: it catches, reports —
    // and that report is the single Sentry capture path — then exits non-zero.
    Artisan::command('phase6:probe-failure', function (): void {
        throw new RuntimeException('phase 6 artisan probe failure');
    });

    $transport = fakeSentryTransport();

    $status = app(ConsoleKernel::class)->handle(
        new ArrayInput(['command' => 'phase6:probe-failure']),
        new BufferedOutput,
    );

    expect($status)->not->toBe(0);

    $errors = $transport->errorEvents();

    expect($errors)->toHaveCount(1)
        ->and($errors[0]->getExceptions()[0]->getValue())->toBe('phase 6 artisan probe failure');
});

it('has exactly one Sentry capture path in the whole application', function () {
    // Duplicate reporting is the failure mode this phase most has to avoid.
    // The single registration lives in bootstrap/app.php; nothing else may
    // call into the SDK, and no manual JobFailed listener may exist.
    expect(File::get(base_path('bootstrap/app.php')))
        ->toContain('Integration::handles($exceptions);');

    $captures = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (preg_match('/captureException|captureMessage|Sentry::|SentrySdk/', $file->getContents()) === 1) {
            $captures[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($captures)->toBe([]);

    // The SDK's queue integration only manages spans and flushes; it does not
    // capture. If that ever changes, the single-event assertions above fail —
    // this pins the reason so the failure is diagnosable.
    expect(File::get(base_path('vendor/sentry/sentry-laravel/src/Sentry/Laravel/Features/QueueIntegration.php')))
        ->not->toContain('captureException');
});

it('exposes no permanent crash or Sentry test route', function () {
    foreach (['routes/web.php', 'routes/api.php', 'routes/auth.php', 'routes/console.php'] as $path) {
        $source = File::get(base_path($path));

        foreach (['debug/crash', 'test-sentry', 'sentry-test', '/throw', 'phase6-exception-probe'] as $forbidden) {
            expect(str_contains($source, $forbidden))
                ->toBeFalse("{$path} must not expose {$forbidden}");
        }
    }
});
