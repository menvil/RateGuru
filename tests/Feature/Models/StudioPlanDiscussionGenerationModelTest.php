<?php

use App\Enums\StudioDiscussionGenerationError;
use App\Enums\StudioDiscussionGenerationMode;
use App\Enums\StudioDiscussionGenerationStatus;
use App\Models\StudioDiscussionNode;
use App\Models\StudioPlan;
use App\Models\StudioPlanDiscussionGeneration;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * The history of a plan's discussion generations: a row per attempt, gone
 * with its plan, outliving the comment it wrote again, holding a proposal
 * apart from whether it was taken in — and reaching no page or API.
 */
afterEach(function () {
    Carbon::setTestNow();
});

it('has the columns its contract names', function () {
    expect(Schema::getColumnListing('studio_plan_discussion_generations'))->toEqualCanonicalizing([
        'id', 'studio_plan_id', 'requested_by_user_id', 'mode', 'target_node_id', 'persona_user_ids', 'parameters',
        'source_lock_version', 'source_image_asset_id', 'source_analysis_hash', 'source_discussion_hash', 'source_personas_hash',
        'status', 'attempt_id', 'error_code', 'result', 'model', 'request_id', 'input_tokens', 'output_tokens', 'total_tokens',
        'latency_ms', 'requested_at', 'started_at', 'finished_at', 'applied_at', 'created_at', 'updated_at',
    ]);
});

it('casts what it keeps, and takes nothing by mass assignment', function () {
    $generation = StudioPlanDiscussionGeneration::factory()->completed(['nodes' => []])->create(['error_code' => null]);

    expect($generation->fresh())
        ->mode->toBe(StudioDiscussionGenerationMode::FullDiscussion)
        ->status->toBe(StudioDiscussionGenerationStatus::Completed)
        ->result->toBe(['nodes' => []])
        ->parameters->toMatchArray(['root_count' => 3, 'tone' => 'cheeky'])
        ->and($generation->plan)->toBeInstanceOf(StudioPlan::class)
        ->and($generation->requestedBy->isAdmin())->toBeTrue()
        ->and(StudioPlanDiscussionGeneration::factory()->failed(StudioDiscussionGenerationError::RateLimited)->create()->fresh()->error_code)->toBe(StudioDiscussionGenerationError::RateLimited)
        ->and(fn () => (new StudioPlanDiscussionGeneration)->fill(['status' => 'completed']))->toThrow(MassAssignmentException::class);
});

it('goes with its plan', function () {
    $generation = StudioPlanDiscussionGeneration::factory()->create();

    StudioPlan::query()->whereKey($generation->studio_plan_id)->delete();

    expect(StudioPlanDiscussionGeneration::query()->whereKey($generation->id)->exists())->toBeFalse();
});

it('outlives the comment it wrote again, keeping that comment\'s id in its parameters', function () {
    $node = StudioDiscussionNode::factory()->create();
    $generation = StudioPlanDiscussionGeneration::factory()->create([
        'studio_plan_id' => $node->studio_plan_id,
        'mode' => StudioDiscussionGenerationMode::RegenerateComment,
        'target_node_id' => $node->id,
        'parameters' => ['mode' => 'regenerate_comment', 'target_node_id' => $node->id],
    ]);

    expect($generation->targetNode->is($node))->toBeTrue();

    $node->delete();

    expect($generation->fresh())->target_node_id->toBeNull()
        ->and($generation->fresh()->targetNodeId())->toBe($node->id);
});

it('takes one waiting or running far longer than any live one could as lost', function () {
    Carbon::setTestNow('2026-10-20 10:00:00');
    $queued = StudioPlanDiscussionGeneration::factory()->create();
    $running = StudioPlanDiscussionGeneration::factory()->running()->create();
    $done = StudioPlanDiscussionGeneration::factory()->completed()->create();

    expect($queued->isUnderway(now()))->toBeTrue()
        ->and($running->isUnderway(now()))->toBeTrue()
        ->and($done->isUnderway(now()))->toBeFalse()
        ->and($queued->isStale(now()->addSeconds(179)))->toBeFalse()
        ->and($queued->isStale(now()->addSeconds(180)))->toBeTrue()
        ->and($running->isStale(now()->addSeconds(180)))->toBeTrue()
        ->and($done->isStale(now()->addDay()))->toBeFalse();
});

it('reaches no route, controller or public resource: only the editor\'s Discussion step shows it', function () {
    $files = collect(['routes', 'app/Http', 'app/Livewire', 'app/Filament', 'resources/views'])
        ->flatMap(fn (string $directory) => File::allFiles(base_path($directory)))
        ->filter(fn ($file): bool => str_contains((string) file_get_contents($file->getPathname()), 'DiscussionGeneration'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($files)->toBe(['Admin/StudioPlanDiscussionEditor.php']);
});

it('rolls back and runs again: the table goes and comes back, the plans and their discussions untouched', function () {
    $node = StudioDiscussionNode::factory()->create(['body' => 'Kept.']);
    $path = 'database/migrations/2026_10_20_120000_create_studio_plan_discussion_generations_table.php';

    $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_discussion_generations'))->toBeFalse()
        ->and(StudioDiscussionNode::query()->findOrFail($node->id)->body)->toBe('Kept.');

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect(Schema::hasTable('studio_plan_discussion_generations'))->toBeTrue();
});
