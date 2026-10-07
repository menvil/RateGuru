<?php

use App\Models\Category;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationIssue;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * The temporary home of background suggestions: small metadata and
 * provider-sized chunks under separate keys, plain arrays only, a fixed
 * lifetime, and every change of state made under the batch's lock.
 */
function generationCache(): Repository
{
    return Cache::store((string) config('translation.bulk.cache_store'));
}

function generationStore(): ProjectTranslationGenerationStore
{
    return app(ProjectTranslationGenerationStore::class);
}

/** Whether a value is made of strings, integers, floats, booleans, null and arrays of those alone. */
function isPlainData(mixed $value): bool
{
    return match (true) {
        is_array($value) => array_reduce($value, fn (bool $plain, mixed $item): bool => $plain && isPlainData($item), true),
        default => $value === null || is_scalar($value),
    };
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 10:00:00');
    ProjectSettings::factory()->create();
    Queue::fake();
    useScriptedTranslationProvider(ScriptedTranslationProvider::translating());
    $this->admin = User::factory()->admin()->create();
    [$this->target, $this->other] = twoTranslatedLocales();
    Category::factory()->create(['name' => 'Secret source sentence', 'name_translations' => null, 'is_active' => true]);
});

it('keeps small metadata apart from the chunks, with no text in the metadata or in any key', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];

    $meta = generationCache()->get("translation-generation:batch:{$batch}");
    $chunk = generationCache()->get("translation-generation:batch:{$batch}:chunk:{$meta['chunk_ids'][0]}");

    expect(array_keys($meta))->toBe([
        'id', 'user_id', 'target_locale', 'status', 'planned_provider', 'provider_max_items', 'provider_max_payload_chars',
        'chunk_ids', 'total', 'created_at', 'expires_at', 'completed_at', 'version',
    ])
        ->and($meta['user_id'])->toBe($this->admin->id)
        ->and($meta['target_locale'])->toBe($this->target)
        ->and($meta['planned_provider'])->toBe('scripted')
        ->and(json_encode($meta))->not->toContain('Secret source sentence')
        ->and(json_encode($chunk))->toContain('Secret source sentence')
        ->and($chunk['items'][0])->toHaveKeys(['id', 'fingerprint', 'snapshot', 'status'])
        ->and($chunk['items'][0]['snapshot'])->toHaveKeys(['id', 'source_locale', 'source_text', 'content_type', 'context', 'max_length', 'multiline', 'placeholders', 'existing_translations']);
});

it('stores plain arrays only, never an object the cache would have to unserialize', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    runTranslationGenerationJobs();

    $meta = generationCache()->get("translation-generation:batch:{$batch}");
    $values = [
        $meta,
        generationCache()->get("translation-generation:active:{$this->admin->id}:{$this->target}"),
        ...array_map(fn (string $chunk) => generationCache()->get("translation-generation:batch:{$batch}:chunk:{$chunk}"), $meta['chunk_ids']),
    ];

    foreach ($values as $value) {
        expect(isPlainData($value))->toBeTrue();
    }
});

it('points each administrator to their own batch per language, and each language to the one running', function () {
    $other = User::factory()->admin()->create();
    $mine = startTranslationGeneration($this->admin, $this->target)['batch'];
    $mineElsewhere = startTranslationGeneration($this->admin, $this->other)['batch'];

    expect(generationStore()->activeBatchId($this->admin->id, $this->target))->toBe($mine)
        ->and(generationStore()->activeBatchId($this->admin->id, $this->other))->toBe($mineElsewhere)
        ->and(generationStore()->activeBatchId($other->id, $this->target))->toBeNull()
        ->and(generationStore()->runningBatchId($this->target))->toBe($mine)
        ->and(generationStore()->runningBatchId($this->other))->toBe($mineElsewhere)
        ->and(translationGenerationOf($other, $this->target))->toBeNull();

    // Finished, the language no longer has a running batch; the administrator still has theirs.
    runTranslationGenerationJobs();

    expect(generationStore()->runningBatchId($this->target))->toBeNull()
        ->and(generationStore()->activeBatchId($this->admin->id, $this->target))->toBe($mine);
});

it('expires 48 hours after it was created, however often it is read', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    runTranslationGenerationJobs();
    $meta = generationCache()->get("translation-generation:batch:{$batch}");

    expect(CarbonImmutable::parse($meta['expires_at'])->equalTo(CarbonImmutable::parse($meta['created_at'])->addHours(48)))->toBeTrue();

    foreach ([1, 12, 24, 47] as $hour) {
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00')->addHours($hour));
        expect(translationGenerationOf($this->admin, $this->target)['batch'])->toBe($batch);
    }

    Carbon::setTestNow(Carbon::parse('2026-10-07 10:00:00')->addHours(48)->addSecond());

    expect(translationGenerationOf($this->admin, $this->target))->toBeNull()
        ->and(generationCache()->get("translation-generation:batch:{$batch}"))->toBeNull();
});

it('cleans up an administrator\'s pointer to a batch that is gone', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    generationCache()->forget("translation-generation:batch:{$batch}");

    expect(translationGenerationOf($this->admin, $this->target))->toBeNull()
        ->and(generationStore()->activeBatchId($this->admin->id, $this->target))->toBeNull();
});

it('lets a chunk be claimed once', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];

    $first = generationStore()->claim($batch, 'c1', CarbonImmutable::now());
    $second = generationStore()->claim($batch, 'c1', CarbonImmutable::now());

    expect($first['chunk']['status'])->toBe('running')
        ->and($first['chunk']['running_started_at'])->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(generationStore()->claim($batch, 'c9', CarbonImmutable::now()))->toBeNull()
        ->and(generationStore()->claim('00000000-0000-0000-0000-000000000000', 'c1', CarbonImmutable::now()))->toBeNull();
});

it('gives up a chunk a worker claimed and never finished, once 180 seconds have passed', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    generationStore()->claim($batch, 'c1', CarbonImmutable::now());

    Carbon::setTestNow(Carbon::now()->addSeconds(179));
    expect(translationGenerationOf($this->admin, $this->target)['status'])->toBe('running');

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $generation = translationGenerationOf($this->admin, $this->target);

    expect($generation['status'])->toBe('completed')
        ->and($generation['counts']['failed'])->toBe($generation['counts']['total'])
        ->and(collect($generation['items'])->pluck('issue')->unique()->all())->toBe([ProjectTranslationGenerationIssue::WorkerInterrupted->value])
        ->and(generationStore()->runningBatchId($this->target))->toBeNull();
});

it('keeps a result that arrives after its chunk was given up as interrupted — it was paid for', function () {
    $batch = startTranslationGeneration($this->admin, $this->target)['batch'];
    $claimed = generationStore()->claim($batch, 'c1', CarbonImmutable::now());
    Carbon::setTestNow(Carbon::now()->addSeconds(200));
    translationGenerationOf($this->admin, $this->target);

    $unit = $claimed['chunk']['items'][0]['id'];
    generationStore()->complete($batch, 'c1', [$unit => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Spät, aber da']], CarbonImmutable::now());

    expect(translationGenerationItem(translationGenerationOf($this->admin, $this->target), $unit))->toMatchArray(['status' => 'ready', 'text' => 'Spät, aber da']);
});

it('raises the version with every change, so an unchanged batch can be told apart', function () {
    $batch = startTranslationGeneration($this->admin, $this->target);
    $before = translationGenerationOf($this->admin, $this->target)['version'];

    expect(translationGenerationOf($this->admin, $this->target)['version'])->toBe($before);

    runTranslationGenerationJobs();

    expect(translationGenerationOf($this->admin, $this->target)['version'])->toBeGreaterThan($before)
        ->and($batch['version'])->toBe($before);
});
