<?php

use App\Actions\Translations\UpdateProjectTranslationAction;
use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Jobs\Translations\GenerateProjectTranslationChunkJob;
use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use App\Support\TranslationEngine\Data\TranslationProviderLimits;
use App\Support\TranslationEngine\Enums\TranslationErrorCode;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use App\Support\Translations\ProjectTranslationCatalog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Generate missing: every translation a language is missing, planned into
 * provider-sized chunks, stored as the administrator's batch and queued — never
 * translated inside the request that starts it.
 */
function generationRefusal(Closure $start): ?string
{
    try {
        $start();
    } catch (CannotGenerateTranslationsException $exception) {
        return $exception->reason.': '.$exception->getMessage();
    }

    return null;
}

/** @return list<string> the units the catalog lists as missing in a language, in its order */
function missingTranslationUnits(string $locale): array
{
    return array_values(array_map(
        fn ($unit): string => $unit->id,
        array_filter(app(ProjectTranslationCatalog::class)->units(), fn ($unit): bool => $unit->requiresTranslation() && $unit->translation($locale) === null),
    ));
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    Queue::fake();
    Http::preventStrayRequests();
    $this->provider = useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $this->admin = User::factory()->admin()->create();
    [$this->target, $this->other] = twoTranslatedLocales();
});

it('plans every missing translation of the language and queues it, without calling a provider', function () {
    Category::factory()->count(3)->create(['name_translations' => null, 'is_active' => true]);
    $missing = missingTranslationUnits($this->target);

    $generation = startTranslationGeneration($this->admin, $this->target);

    expect($generation['status'])->toBe('queued')
        ->and($generation['counts'])->toMatchArray(['total' => count($missing), 'pending' => count($missing), 'ready' => 0])
        ->and(array_column($generation['items'], 'unit'))->toBe($missing)
        ->and($this->provider->received)->toBe([]);

    Queue::assertPushed(GenerateProjectTranslationChunkJob::class, fn (GenerateProjectTranslationChunkJob $job): bool => $job->batchId === $generation['batch']);
});

it('includes only translations that are missing: not saved ones, not blank English, not content no visitor sees', function () {
    $missing = Category::factory()->create(['name' => 'Dogs', 'name_translations' => null, 'is_active' => true]);
    $saved = Category::factory()->create(['name' => 'Cats', 'name_translations' => [$this->target => 'Кошки'], 'is_active' => true]);
    $inactive = Category::factory()->create(['name' => 'Hidden', 'name_translations' => null, 'is_active' => false]);
    $blank = RatingGroup::factory()->create(['label' => 'Vibe', 'description' => '', 'is_active' => true]);
    $archived = RatingOption::factory()->for(RatingGroup::factory()->create(['is_active' => true]), 'group')->create(['is_active' => true, 'archived_at' => now()]);

    $units = array_column(startTranslationGeneration($this->admin, $this->target)['items'], 'unit');

    expect($units)->toContain("categories:{$missing->id}:name")
        ->not->toContain("categories:{$saved->id}:name")
        ->not->toContain("categories:{$inactive->id}:name")
        ->not->toContain("rating_groups:{$blank->id}:description")
        ->not->toContain("rating_options:{$archived->id}:label")
        ->and($units)->toBe(missingTranslationUnits($this->target));
});

it('cuts the work into one provider request per job, with the configured provider\'s own limits', function () {
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating(new TranslationProviderLimits(4, 60_000)));
    Category::factory()->count(9)->create(['name_translations' => null, 'is_active' => true]);
    $missing = count(missingTranslationUnits($this->target));

    startTranslationGeneration($this->admin, $this->target);

    Queue::assertPushed(GenerateProjectTranslationChunkJob::class, (int) ceil($missing / 4));
});

it('generates into an installed language that is not offered yet', function () {
    offerLocales([$this->target]);

    expect(startTranslationGeneration($this->admin, $this->other)['status'])->toBe('queued');
});

it('refuses what it cannot generate, queuing nothing and creating no batch', function (Closure $start, string $reason) {
    expect(generationRefusal(fn () => $start($this)))->toStartWith($reason);

    Queue::assertNothingPushed();
    expect(translationGenerationOf($this->admin, $this->target))->toBeNull();
})->with([
    'English' => [fn ($test) => startTranslationGeneration($test->admin, 'en'), 'reference_locale'],
    'a language that is not installed' => [fn ($test) => startTranslationGeneration($test->admin, 'xx'), 'unknown_locale'],
    'a moderator' => [fn ($test) => startTranslationGeneration(User::factory()->moderator()->create(), $test->target), 'not_allowed'],
    'a member' => [fn ($test) => startTranslationGeneration(User::factory()->create(), $test->target), 'not_allowed'],
]);

it('says machine translation is not configured, and stores and queues nothing', function () {
    configureOpenAiTranslation(['api_key' => null]);

    expect(generationRefusal(fn () => startTranslationGeneration($this->admin, $this->target)))
        ->toBe('engine_failed: Machine translation is not configured.');

    Queue::assertNothingPushed();
    expect(translationGenerationOf($this->admin, $this->target))->toBeNull();
    Http::assertNothingSent();
});

it('does nothing when nothing is missing', function () {
    foreach (missingTranslationUnits($this->target) as $unit) {
        app(UpdateProjectTranslationAction::class)->handle($this->admin, $unit, $this->target, 'Übersetzt');
    }

    expect(generationRefusal(fn () => startTranslationGeneration($this->admin, $this->target)))
        ->toBe('nothing_missing: Nothing is missing for this language.');

    Queue::assertNothingPushed();
});

it('returns the administrator\'s running batch instead of queuing it again', function () {
    $first = startTranslationGeneration($this->admin, $this->target);
    $pushed = Queue::pushed(GenerateProjectTranslationChunkJob::class)->count();

    $again = startTranslationGeneration($this->admin, $this->target);

    expect($again['batch'])->toBe($first['batch'])
        ->and(Queue::pushed(GenerateProjectTranslationChunkJob::class)->count())->toBe($pushed);
});

it('returns a finished batch while its suggestions wait for review, rather than paying for them again', function () {
    $first = startTranslationGeneration($this->admin, $this->target);
    runTranslationGenerationJobs();
    $received = count($this->provider->received);

    $again = startTranslationGeneration($this->admin, $this->target);

    expect($again['batch'])->toBe($first['batch'])
        ->and($again['status'])->toBe('completed')
        ->and($again['counts']['ready'])->toBeGreaterThan(0)
        ->and($this->provider->received)->toHaveCount($received);
});

it('starts a new batch once the last one has nothing ready and nothing running', function () {
    useScriptedTranslationProvider(failingTranslationProvider(TranslationErrorCode::ProviderUnavailable));
    $first = startTranslationGeneration($this->admin, $this->target);
    runTranslationGenerationJobs();

    expect(translationGenerationOf($this->admin, $this->target))->toMatchArray(['status' => 'completed'])
        ->and(translationGenerationOf($this->admin, $this->target)['counts']['ready'])->toBe(0);

    $second = startTranslationGeneration($this->admin, $this->target);

    expect($second['batch'])->not->toBe($first['batch'])
        ->and($second['status'])->toBe('queued');
});

it('refuses a language another administrator is generating, until that generation finishes', function () {
    $other = User::factory()->admin()->create();
    startTranslationGeneration($other, $this->target);
    $pushed = Queue::pushed(GenerateProjectTranslationChunkJob::class)->count();
    $language = config("locales.supported.{$this->target}.label");

    expect(generationRefusal(fn () => startTranslationGeneration($this->admin, $this->target)))
        ->toBe("already_running: {$language} translations are already being generated by another administrator. Try again when that generation finishes.")
        ->and(Queue::pushed(GenerateProjectTranslationChunkJob::class)->count())->toBe($pushed);

    // Once it has finished, the language is free; the other administrator's drafts stay theirs.
    runTranslationGenerationJobs();

    expect(startTranslationGeneration($this->admin, $this->target)['status'])->toBe('queued');
});

it('lets another language run alongside', function () {
    $other = User::factory()->admin()->create();
    startTranslationGeneration($other, $this->target);

    expect(startTranslationGeneration($this->admin, $this->other)['status'])->toBe('queued');
});

it('answers safely when the draft store cannot be reached, calling no provider', function () {
    config(['translation.bulk.cache_store' => 'unreachable']);
    config(['cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'nowhere']]);

    expect(generationRefusal(fn () => startTranslationGeneration($this->admin, $this->target)))
        ->toStartWith('store_unavailable');

    Queue::assertNothingPushed();
    expect($this->provider->received)->toBe([]);
});

it('fails the chunks it could not queue, rather than leaving them waiting forever', function () {
    Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue down'));

    expect(generationRefusal(fn () => startTranslationGeneration($this->admin, $this->target)))
        ->toBe('dispatch_failed: Background generation could not be started. Try again.');

    $generation = translationGenerationOf($this->admin, $this->target);

    expect($generation['status'])->toBe('completed')
        ->and($generation['counts']['pending'])->toBe(0)
        ->and($generation['counts']['failed'])->toBe($generation['counts']['total'])
        ->and(app(ProjectTranslationGenerationStore::class)->runningBatchId($this->target))->toBeNull();
});
