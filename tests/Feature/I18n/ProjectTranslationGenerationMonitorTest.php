<?php

use App\Exceptions\Translations\CannotGenerateTranslationsException;
use App\Models\User;
use App\Support\Translations\Generation\ProjectTranslationGenerationItemStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationMonitor;
use App\Support\Translations\Generation\ProjectTranslationGenerationProgress;
use App\Support\Translations\Generation\ProjectTranslationGenerationStatus;
use App\Support\Translations\Generation\ProjectTranslationGenerationStore;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * The background generations there are now, for Translation AI: found through
 * the store's pointers for a known list of languages, never by searching the
 * store, and handed on as counts and times — never a unit, a text or a
 * snapshot.
 */
function generationMonitor(): ProjectTranslationGenerationMonitor
{
    return app(ProjectTranslationGenerationMonitor::class);
}

function monitoredStore(): ProjectTranslationGenerationStore
{
    return app(ProjectTranslationGenerationStore::class);
}

beforeEach(function () {
    $this->now = CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC');
    CarbonImmutable::setTestNow($this->now);
    $this->viewer = User::factory()->admin()->create();
    $this->other = User::factory()->admin()->create();
    // Two languages to generate, among every installed one the monitor is asked about.
    [$this->first, $this->second] = twoTranslatedLocales();
    $this->languages = translatedLocales();
});

it('finds the generation of each language now and the viewer’s own, as counts and times', function () {
    $running = monitoredStore()->create($this->other->id, $this->first, translationGenerationPlan($this->first, 2, 3), $this->now->subMinutes(5));
    monitoredStore()->claim($running['meta']['id'], 'c1', $this->now->subMinutes(4));
    monitoredStore()->complete($running['meta']['id'], 'c1', [
        'categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Eins', 'provider' => 'openai', 'model' => 'gpt-6-luna'],
        'categories:12:name' => ['status' => ProjectTranslationGenerationItemStatus::Failed, 'issue' => 'constraint_violation', 'message' => 'Too long.'],
        'categories:13:name' => ['status' => ProjectTranslationGenerationItemStatus::Skipped, 'issue' => 'source_changed', 'message' => 'English changed.'],
    ], $this->now->subMinutes(3));
    $own = monitoredStore()->create($this->viewer->id, $this->second, translationGenerationPlan($this->second, 1, 2), $this->now->subHour());
    monitoredStore()->claim($own['meta']['id'], 'c1', $this->now->subHour());
    monitoredStore()->complete($own['meta']['id'], 'c1', [
        'categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Один'],
        'categories:12:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Два'],
    ], $this->now->subMinutes(59));
    monitoredStore()->resolve($own['meta']['id'], ['categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Saved]], $this->now->subMinutes(30));

    expect(generationMonitor()->current($this->languages, $this->viewer->id, $this->now))->toEqual([
        new ProjectTranslationGenerationProgress(
            locale: $this->first, status: ProjectTranslationGenerationStatus::Running, total: 6, processed: 3, ready: 1, failed: 1, skipped: 1, saved: 0, discarded: 0,
            createdAt: $this->now->subMinutes(5), expiresAt: $this->now->subMinutes(5)->addHours(48), completedAt: null, running: true, startedByViewer: false,
        ),
        new ProjectTranslationGenerationProgress(
            locale: $this->second, status: ProjectTranslationGenerationStatus::Completed, total: 2, processed: 2, ready: 1, failed: 0, skipped: 0, saved: 1, discarded: 0,
            createdAt: $this->now->subHour(), expiresAt: $this->now->subHour()->addHours(48), completedAt: $this->now->subMinutes(59), running: false, startedByViewer: true,
        ),
    ]);
});

it('shows a running generation once, whoever holds it, and nobody else’s finished one', function () {
    monitoredStore()->create($this->viewer->id, $this->first, translationGenerationPlan($this->first), $this->now);
    $finished = monitoredStore()->create($this->other->id, $this->second, translationGenerationPlan($this->second, 1, 1), $this->now);
    monitoredStore()->claim($finished['meta']['id'], 'c1', $this->now);
    monitoredStore()->complete($finished['meta']['id'], 'c1', ['categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Ready, 'text' => 'Один']], $this->now);

    $generations = generationMonitor()->current($this->languages, $this->viewer->id, $this->now);

    expect(array_map(fn (ProjectTranslationGenerationProgress $generation): array => [$generation->locale, $generation->running, $generation->startedByViewer], $generations))
        ->toBe([[$this->first, true, true]]);
});

it('fails a chunk a worker abandoned as interrupted, as Translation Center would find it', function () {
    $batch = monitoredStore()->create($this->viewer->id, $this->first, translationGenerationPlan($this->first, 1, 2), $this->now->subMinutes(10));
    monitoredStore()->claim($batch['meta']['id'], 'c1', $this->now->subMinutes(10));

    $generation = generationMonitor()->current([$this->first], $this->viewer->id, $this->now)[0];

    expect([$generation->status, $generation->processed, $generation->failed])->toBe([ProjectTranslationGenerationStatus::Completed, 2, 2]);
});

it('finds nothing for languages without a generation, or once a generation has expired', function () {
    monitoredStore()->create($this->viewer->id, $this->first, translationGenerationPlan($this->first), $this->now->subHours(49));

    expect(generationMonitor()->current($this->languages, $this->viewer->id, $this->now->addHours(1)))->toBe([])
        ->and(generationMonitor()->current([], null, $this->now))->toBe([]);
});

it('reads only the pointers of the languages it is given, in one read each, never searching the store', function () {
    $store = new class extends ArrayStore
    {
        /** @var list<list<string>|string> */
        public array $reads = [];

        private bool $inMany = false;

        public function get($key)
        {
            if (! $this->inMany) {
                $this->reads[] = $key;
            }

            return parent::get($key);
        }

        // One read of several keys: the array store answers it key by key, which is not another read.
        public function many(array $keys)
        {
            $this->reads[] = $keys;
            $this->inMany = true;

            try {
                return parent::many($keys);
            } finally {
                $this->inMany = false;
            }
        }
    };
    Cache::extend('recording', fn () => new Repository($store));
    config(['cache.stores.recording' => ['driver' => 'recording'], 'translation.bulk.cache_store' => 'recording']);
    $batch = monitoredStore()->create($this->other->id, $this->second, translationGenerationPlan($this->second, 1, 1), $this->now);
    $store->reads = [];

    generationMonitor()->current([$this->first, $this->second], $this->viewer->id, $this->now);

    expect($store->reads)->toBe([
        ["translation-generation:running:{$this->first}", "translation-generation:running:{$this->second}"],
        ["translation-generation:active:{$this->viewer->id}:{$this->first}", "translation-generation:active:{$this->viewer->id}:{$this->second}"],
        "translation-generation:batch:{$batch['meta']['id']}",
        ["translation-generation:batch:{$batch['meta']['id']}:chunk:c1"],
    ]);
});

it('says the store cannot be reached rather than failing', function () {
    config(['translation.bulk.cache_store' => 'unreachable', 'cache.stores.unreachable' => ['driver' => 'redis', 'connection' => 'nowhere']]);

    expect(fn () => generationMonitor()->current([$this->first], $this->viewer->id, $this->now))
        ->toThrow(CannotGenerateTranslationsException::class);
});

it('hands monitoring counts and times only, with no unit, text, snapshot, message or owner', function () {
    $batch = monitoredStore()->create($this->viewer->id, $this->first, translationGenerationPlan($this->first, 1, 1), $this->now);
    monitoredStore()->claim($batch['meta']['id'], 'c1', $this->now);
    monitoredStore()->complete($batch['meta']['id'], 'c1', ['categories:11:name' => ['status' => ProjectTranslationGenerationItemStatus::Failed, 'issue' => 'constraint_violation', 'message' => 'Too long.']], $this->now);

    $progress = ProjectTranslationGenerationStore::progress(monitoredStore()->read($batch['meta']['id'], $this->now));

    expect(array_keys($progress))->toBe(['locale', 'status', 'total', 'pending', 'ready', 'failed', 'skipped', 'saved', 'discarded', 'created_at', 'expires_at', 'completed_at'])
        ->and(json_encode($progress))->not->toContain('categories:11:name')->not->toContain('Category')->not->toContain('Too long.');
});
