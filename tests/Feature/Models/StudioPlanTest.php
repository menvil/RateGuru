<?php

use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\StudioPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A publication plan: one future post, kept as its own row rather than as a
 * post, a queued job or a blob of JSON. A Draft may be as empty as an editor
 * leaves it; the database itself holds the closed sets and the references.
 */

/** A row written straight to the table, as nothing in the application writes one. */
function insertStudioPlanRow(array $attributes = []): int
{
    return (int) DB::table('studio_plans')->insertGetId([
        'created_by_user_id' => User::factory()->admin()->create()->id,
        'tag_ids' => '[]',
        'author_answer_option_ids' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
}

it('has the columns its contract names, and none a later stage adds', function () {
    expect(Schema::getColumnListing('studio_plans'))->toEqualCanonicalizing([
        'id', 'status', 'author_user_id', 'created_by_user_id', 'image_asset_id', 'title', 'description',
        'source_url', 'category_id', 'tag_ids', 'author_answer_option_ids', 'publication_mode', 'publish_at',
        'lock_version', 'created_at', 'updated_at',
    ]);
});

it('starts as an empty Draft, published at once, at its first version', function () {
    $plan = StudioPlan::query()->findOrFail(insertStudioPlanRow());

    expect($plan)
        ->status->toBe(StudioPlanStatus::Draft)
        ->publication_mode->toBe(StudioPlanPublicationMode::Now)
        ->lock_version->toBe(1)
        ->author_user_id->toBeNull()
        ->image_asset_id->toBeNull()
        ->title->toBeNull()
        ->category_id->toBeNull()
        ->publish_at->toBeNull()
        ->tag_ids->toBe([])
        ->author_answer_option_ids->toBe([])
        ->and($plan->isDraft())->toBeTrue()
        ->and(new StudioPlan)
        ->status->toBe(StudioPlanStatus::Draft)
        ->publication_mode->toBe(StudioPlanPublicationMode::Now)
        ->lock_version->toBe(1)
        ->tag_ids->toBe([]);
});

it('reaches its author, its creator, its photo and its category', function () {
    $persona = User::factory()->persona()->create();
    $category = Category::factory()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->withImage()->create(['category_id' => $category->id]);

    expect($plan->author->is($persona))->toBeTrue()
        ->and($plan->createdBy->isAdmin())->toBeTrue()
        ->and($plan->imageAsset)->toBeInstanceOf(MediaAsset::class)
        ->and($plan->imageAsset->owner_user_id)->toBe($plan->created_by_user_id)
        ->and($plan->category->is($category))->toBeTrue();
});

it('keeps its lists of ids and its date as they were written', function () {
    $plan = StudioPlan::factory()->create([
        'tag_ids' => [3, 7],
        'author_answer_option_ids' => [11],
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2021-03-04 05:06:07'),
        'lock_version' => 4,
    ]);

    $fresh = StudioPlan::query()->findOrFail($plan->id);

    expect($fresh)
        ->tag_ids->toBe([3, 7])
        ->author_answer_option_ids->toBe([11])
        ->publication_mode->toBe(StudioPlanPublicationMode::AtDate)
        ->lock_version->toBe(4)
        ->and($fresh->publish_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->publish_at->toDateTimeString())->toBe('2021-03-04 05:06:07');

    expect(json_decode($fresh->toJson(), true))
        ->status->toBe('draft')
        ->publication_mode->toBe('at_date')
        ->tag_ids->toBe([3, 7])
        ->author_answer_option_ids->toBe([11])
        ->lock_version->toBe(4)
        ->publish_at->toStartWith('2021-03-04T05:06:07');
});

it('lets no mass assignment set what only the server writes', function () {
    $plan = new StudioPlan;
    $plan->fill([
        'title' => 'Kept',
        'status' => StudioPlanStatus::Approved,
        'created_by_user_id' => 99,
        'image_asset_id' => 98,
        'lock_version' => 97,
    ]);

    expect($plan->title)->toBe('Kept')
        ->and($plan->status)->toBe(StudioPlanStatus::Draft)
        ->and($plan->created_by_user_id)->toBeNull()
        ->and($plan->image_asset_id)->toBeNull()
        ->and($plan->lock_version)->toBe(1);
});

it('lets the database itself refuse a value outside the closed sets', function (string $column, string $value) {
    expect(fn () => insertStudioPlanRow([$column => $value]))->toThrow(QueryException::class);
})->with([
    'a status' => ['status', 'published'],
    'a publication mode' => ['publication_mode', 'later'],
]);

it('needs a creator', function () {
    expect(fn () => DB::table('studio_plans')->insert([
        'tag_ids' => '[]',
        'author_answer_option_ids' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('points only at rows that exist', function (string $column) {
    expect(fn () => insertStudioPlanRow([$column => 2_000_000_000]))->toThrow(QueryException::class);
})->with(['author_user_id', 'created_by_user_id', 'image_asset_id', 'category_id']);

it('keeps the accounts it names: neither can be deleted from under it', function (string $column) {
    $user = User::factory()->persona()->create();
    insertStudioPlanRow([$column => $user->id]);

    expect(fn () => DB::table('users')->where('id', $user->id)->delete())->toThrow(QueryException::class);
})->with(['author_user_id', 'created_by_user_id']);

it('keeps its photo: the asset row cannot be deleted from under it', function () {
    $plan = StudioPlan::factory()->withImage()->create();

    expect(fn () => DB::table('media_assets')->where('id', $plan->image_asset_id)->delete())->toThrow(QueryException::class);
});

it('loses only the category when the category itself is deleted', function () {
    $category = Category::factory()->create();
    $plan = StudioPlan::factory()->create(['category_id' => $category->id, 'title' => 'Still here']);

    DB::table('categories')->where('id', $category->id)->delete();

    expect($plan->fresh())->category_id->toBeNull()->title->toBe('Still here');
});

it('stays a draft of its author through the author\'s sanction and deletion', function () {
    $persona = User::factory()->persona()->create();
    $plan = StudioPlan::factory()->authoredBy($persona)->create();

    $persona->forceFill(['status' => 'banned'])->save();
    $persona->forceFill(['status' => 'deleted', 'anonymized_at' => now()])->save();

    expect($plan->fresh())->author_user_id->toBe($persona->id)->status->toBe(StudioPlanStatus::Draft);
});
