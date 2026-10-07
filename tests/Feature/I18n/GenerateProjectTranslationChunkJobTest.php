<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Enums\UserRole;
use App\Jobs\Translations\GenerateProjectTranslationChunkJob;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\TranslationEngine\Data\TranslationBatchRequest;
use App\Support\TranslationEngine\Data\TranslationProviderCall;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\TranslationEngine\Exceptions\TranslationProviderException;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\ProjectTranslationCatalog;
use App\Support\Translations\ProjectTranslationRequestFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * One queued job: one chunk of a Generate missing batch, at most one provider
 * request, sent only after everything it was planned on has been checked again.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    [$this->target] = twoTranslatedLocales();
    // Only these three are missing: everything the project ships is translated first.
    foreach (app(ProjectTranslationCatalog::class)->units() as $unit) {
        if ($unit->requiresTranslation() && $unit->translation($this->target) === null) {
            app(UpdateProjectTranslationAction::class)->handle($this->admin, $unit->id, $this->target, 'Übersetzt');
        }
    }
    $this->dogs = Category::factory()->create(['name' => 'Dogs', 'name_translations' => null, 'is_active' => true]);
    $this->cats = Category::factory()->create(['name' => 'Cats', 'name_translations' => null, 'is_active' => true]);
    $this->birds = Category::factory()->create(['name' => 'Birds', 'name_translations' => null, 'is_active' => true]);
    $this->unit = fn (Category $category): string => "categories:{$category->id}:name";
});

it('is never retried, and stops well before Redis could hand it to another worker', function () {
    $job = new GenerateProjectTranslationChunkJob('batch', 'c1');
    preg_match('/--timeout=(\d+)/', (string) file_get_contents(base_path('infrastructure/config/supervisor/rateguru-staging-queue.conf')), $worker);
    // The Redis queue's retry_after as the repository ships it — what the targets run with — rather
    // than whatever this test environment happens to configure; and as configured here as well.
    preg_match("/env\('REDIS_QUEUE_RETRY_AFTER', (\d+)\)/", (string) file_get_contents(config_path('queue.php')), $shipped);
    $retryAfter = [(int) $shipped[1], (int) config('queue.connections.redis.retry_after')];

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and(property_exists($job, 'backoff'))->toBeFalse()
        ->and($job->timeout)->toBe(75)
        ->and($retryAfter[0])->toBe(90)
        // Stopped with a margin before a reserved job is released again, never at the same moment.
        ->and($job->timeout)->toBeLessThanOrEqual(min($retryAfter) - 10)
        ->and($job->timeout)->toBeLessThan((int) $worker[1])
        // One provider request, with its own timeout, fits inside the job's.
        ->and((int) config('translation.providers.openai.timeout') + (int) config('translation.providers.openai.connect_timeout'))->toBeLessThan($job->timeout);
});

it('carries only the batch and the chunk, as scalars', function () {
    $parameters = (new ReflectionClass(GenerateProjectTranslationChunkJob::class))->getConstructor()->getParameters();

    expect(array_map(fn (ReflectionParameter $parameter): string => $parameter->getName().':'.$parameter->getType(), $parameters))
        ->toBe(['batchId:string', 'chunkId:string']);
});

it('rebuilds its request from the planned snapshot and sends it to TranslationService, once', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $generation = startTranslationGeneration($this->admin, $this->target);

    expect(runTranslationGenerationJobs())->toBe(1);

    $expected = app(ProjectTranslationRequestFactory::class)->make(
        array_values(app(ProjectTranslationCatalog::class)->findMany(array_map(fn (Category $category): string => ($this->unit)($category), [$this->dogs, $this->cats, $this->birds]))),
        $this->target,
    );

    expect($provider->received)->toHaveCount(1)
        ->and($provider->received[0])->toEqual($expected);

    $after = translationGenerationOf($this->admin, $this->target);

    expect($after['status'])->toBe('completed')
        ->and($after['counts'])->toMatchArray(['total' => 3, 'ready' => 3, 'pending' => 0])
        ->and(translationGenerationItem($after, ($this->unit)($this->dogs)))->toMatchArray(['status' => 'ready', 'text' => "[{$this->target}] Dogs"])
        ->and($generation['batch'])->toBe($after['batch']);
});

it('makes exactly one provider call per planned chunk, however many chunks', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(1, 60_000)));
    startTranslationGeneration($this->admin, $this->target);

    expect(runTranslationGenerationJobs())->toBe(3)
        ->and($provider->chunkSizes())->toBe([1, 1, 1]);
});

it('sends nothing when the same job is delivered twice', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    startTranslationGeneration($this->admin, $this->target);

    runTranslationGenerationJobs();
    runTranslationGenerationJobs();
    runTranslationGenerationJobs();

    expect($provider->received)->toHaveCount(1);
});

it('keeps what one chunk produced when another fails, and fails only that chunk\'s items', function () {
    $provider = useScriptedTranslationProvider(new ScriptedTranslationProvider(
        function (TranslationBatchRequest $request, int $call) {
            if ($call === 2) {
                throw new TranslationProviderException(
                    TranslationErrorCode::ProviderTimeout,
                    TranslationProviderCall::failed('scripted', 'scripted-model', $request->itemIds(), 3, TranslationErrorCode::ProviderTimeout),
                );
            }

            return scriptedTranslationResponse($request, array_map(fn ($item) => ['id' => $item->id, 'text' => "Übersetzt {$item->id}"], $request->items));
        },
        new TranslationProviderLimits(1, 60_000),
    ));
    startTranslationGeneration($this->admin, $this->target);
    runTranslationGenerationJobs();

    $generation = translationGenerationOf($this->admin, $this->target);

    expect($generation['status'])->toBe('completed')
        ->and($generation['counts'])->toMatchArray(['ready' => 2, 'failed' => 1])
        ->and(translationGenerationItem($generation, ($this->unit)($this->cats)))->toMatchArray([
            'status' => 'failed', 'issue' => 'provider_timeout', 'message' => 'The translation provider did not answer in time. Try again.',
        ])
        ->and(translationGenerationItem($generation, ($this->unit)($this->dogs))['status'])->toBe('ready')
        ->and(translationGenerationItem($generation, ($this->unit)($this->birds))['status'])->toBe('ready');
});

it('keeps a failed item failed within a chunk whose other items succeeded', function () {
    useScriptedTranslationProvider(new ScriptedTranslationProvider(
        fn (TranslationBatchRequest $request) => scriptedTranslationResponse($request, array_map(
            fn ($item) => ['id' => $item->id, 'text' => $item->sourceText === 'Cats' ? "too long\n".str_repeat('x', 100) : "Übersetzt {$item->sourceText}"],
            $request->items,
        )),
    ));
    startTranslationGeneration($this->admin, $this->target);
    runTranslationGenerationJobs();

    $generation = translationGenerationOf($this->admin, $this->target);

    expect(translationGenerationItem($generation, ($this->unit)($this->cats)))->toMatchArray(['status' => 'failed', 'issue' => 'constraint_violation'])
        ->and($generation['counts'])->toMatchArray(['ready' => 2, 'failed' => 1]);
});

it('never sends English that changed after the batch was planned, and skips it', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    startTranslationGeneration($this->admin, $this->target);

    $this->dogs->update(['name' => 'Dog lovers']);
    runTranslationGenerationJobs();

    $sent = array_merge(...array_map(fn (TranslationBatchRequest $request): array => array_map(fn ($item) => $item->sourceText, $request->items), $provider->received));
    $generation = translationGenerationOf($this->admin, $this->target);

    expect($sent)->not->toContain('Dogs')->not->toContain('Dog lovers')
        ->and(translationGenerationItem($generation, ($this->unit)($this->dogs)))->toMatchArray([
            'status' => 'skipped', 'issue' => 'source_changed', 'message' => ProjectTranslationGenerationIssue::SourceChanged->message(),
        ]);
});

it('skips, without sending, what was translated meanwhile or left the catalog', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    startTranslationGeneration($this->admin, $this->target);

    app(UpdateProjectTranslationAction::class)->handle($this->admin, ($this->unit)($this->dogs), $this->target, 'Hunde');
    $this->cats->update(['is_active' => false]);
    $this->birds->delete();
    runTranslationGenerationJobs();

    $generation = translationGenerationOf($this->admin, $this->target);

    expect($provider->received)->toBe([])
        ->and(translationGenerationItem($generation, ($this->unit)($this->dogs))['issue'])->toBe('already_translated')
        ->and(translationGenerationItem($generation, ($this->unit)($this->cats))['issue'])->toBe('unit_unavailable')
        ->and(translationGenerationItem($generation, ($this->unit)($this->birds))['issue'])->toBe('unit_unavailable')
        ->and($generation['status'])->toBe('completed');
});

it('skips, without sending, an item whose English was cleared since', function () {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    startTranslationGeneration($this->admin, $this->target);

    $this->dogs->update(['name' => '']);
    runTranslationGenerationJobs();

    $sent = array_merge(...array_map(fn (TranslationBatchRequest $request): array => $request->itemIds(), $provider->received));

    expect($sent)->not->toContain(($this->unit)($this->dogs))->toContain(($this->unit)($this->cats))
        ->and(translationGenerationItem(translationGenerationOf($this->admin, $this->target), ($this->unit)($this->dogs)))
        ->toMatchArray(['status' => 'skipped', 'issue' => 'nothing_to_translate']);
});

it('sends nothing once whoever started it may no longer manage project settings', function (Closure $revoke) {
    $provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];

    $revoke($this->admin);
    runTranslationGenerationJobs();

    $after = app(ProjectTranslationGenerationStore::class)->read($batch, CarbonImmutable::now());

    expect($provider->received)->toBe([])
        ->and($after['meta']['status'])->toBe('completed')
        ->and(collect(ProjectTranslationGenerationStore::items($after))->pluck('issue')->unique()->all())->toBe(['not_allowed']);
})->with([
    'demoted' => fn (User $admin) => $admin->update(['role' => UserRole::Moderator]),
    'deleted' => fn (User $admin) => DB::table('users')->where('id', $admin->id)->delete(),
]);

it('sends nothing when the provider or its limits changed after the batch was planned', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    startTranslationGeneration($this->admin, $this->target);

    $smaller = useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(2, 60_000)));
    runTranslationGenerationJobs();

    $generation = translationGenerationOf($this->admin, $this->target);

    expect($smaller->received)->toBe([])
        ->and($generation['counts']['failed'])->toBe(3)
        ->and(translationGenerationItem($generation, ($this->unit)($this->dogs))['issue'])->toBe('provider_configuration_changed');
});

it('fails what is left of its chunk, safely, when the job itself fails', function () {
    Log::spy();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $generation = startTranslationGeneration($this->admin, $this->target);
    $job = Queue::pushed(GenerateProjectTranslationChunkJob::class)->first();

    $job->failed(new RuntimeException('Connection to the provider reset: secret text "Dogs"'));

    $after = translationGenerationOf($this->admin, $this->target);

    expect($after['status'])->toBe('completed')
        ->and(translationGenerationItem($after, ($this->unit)($this->dogs)))->toMatchArray([
            'status' => 'failed', 'issue' => 'job_failed', 'message' => ProjectTranslationGenerationIssue::JobFailed->message(),
        ])
        ->and(json_encode($after))->not->toContain('secret text')->not->toContain('Connection to the provider reset');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $event, array $context): bool => $event === 'translation.generation_chunk_failed'
        && $context['batch_id'] === $generation['batch']
        && ! str_contains(json_encode($context), 'secret text'));
});

it('reads its catalog units in a fixed number of queries, however large the chunk', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $count = function (): int {
        app(ProjectTranslationGenerationStore::class);
        $admin = User::factory()->admin()->create();
        startTranslationGeneration($admin, $this->target);
        DB::flushQueryLog();
        DB::enableQueryLog();
        runTranslationGenerationJobs(fn (GenerateProjectTranslationChunkJob $job): bool => $job->batchId === app(ProjectTranslationGenerationStore::class)->activeBatchId($admin->id, $this->target));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $few = $count();
    Category::query()->whereIn('id', [$this->dogs->id, $this->cats->id, $this->birds->id])->update(['name_translations' => null]);
    Category::factory()->count(20)->create(['name_translations' => null, 'is_active' => true]);
    Queue::fake();

    expect($count())->toBe($few);
});
