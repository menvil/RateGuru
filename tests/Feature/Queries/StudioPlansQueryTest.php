<?php

use App\Enums\StudioPlanStatus;
use App\Models\StudioPlan;
use App\Models\User;
use App\Queries\Studio\StudioPlansQuery;
use Illuminate\Support\Carbon;

/**
 * Publications & plans: every plan, the most recently saved first, narrowed
 * by state and photo, searched literally over the title, the id and the
 * author's username and names — never a deleted author's — and an order that
 * keeps every plan in its place across pages.
 */
function studioPlansPage(string $search = '', ?StudioPlanStatus $status = null, ?bool $withImage = null, int $page = 1): array
{
    return app(StudioPlansQuery::class)->paginate($search, $status, $withImage, $page)->pluck('id')->all();
}

afterEach(function () {
    Carbon::setTestNow();
});

it('lists every plan, the most recently saved first and the newest first among those saved together', function () {
    $older = StudioPlan::factory()->create(['updated_at' => now()->subDays(2)]);
    $newer = StudioPlan::factory()->create(['updated_at' => now()->subDay()]);
    $sameMomentFirst = StudioPlan::factory()->create(['updated_at' => now()->subHour()]);
    $sameMomentSecond = StudioPlan::factory()->create(['updated_at' => $sameMomentFirst->updated_at]);
    $approved = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create(['updated_at' => now()->subHours(3)]);

    expect(studioPlansPage())->toBe([$sameMomentSecond->id, $sameMomentFirst->id, $approved->id, $newer->id, $older->id]);
});

it('narrows by state and by whether there is a photo', function () {
    $withImage = StudioPlan::factory()->withImage()->create();
    $withoutImage = StudioPlan::factory()->create();
    $approved = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create();

    expect(studioPlansPage(status: StudioPlanStatus::Draft))->toEqualCanonicalizing([$withImage->id, $withoutImage->id])
        ->and(studioPlansPage(withImage: true))->toBe([$withImage->id])
        ->and(studioPlansPage(withImage: false))->toEqualCanonicalizing([$withoutImage->id, $approved->id])
        ->and(studioPlansPage(status: StudioPlanStatus::Draft, withImage: true))->toBe([$withImage->id]);
});

it('searches the title, the id and the author, literally and in any case', function () {
    $persona = User::factory()->persona()->create(['username' => 'night_owl', 'name' => 'Gregor Samsa', 'display_name' => 'Gregor Samsa']);
    $byTitle = StudioPlan::factory()->create(['title' => 'A Quiet Harbour at dawn']);
    $byAuthor = StudioPlan::factory()->authoredBy($persona)->create(['title' => 'Unrelated']);
    $other = StudioPlan::factory()->create(['title' => 'Something else']);

    expect(studioPlansPage('quiet HARBOUR'))->toBe([$byTitle->id])
        ->and(studioPlansPage('#'.$other->id))->toContain($other->id)
        ->and(studioPlansPage((string) $other->id))->toContain($other->id)
        ->and(studioPlansPage('night_'))->toBe([$byAuthor->id])
        ->and(studioPlansPage('@night_owl'))->toBe([$byAuthor->id])
        ->and(studioPlansPage('samsa'))->toBe([$byAuthor->id]);
});

it('treats the characters LIKE gives meaning to as themselves, and anything else as text', function (string $search) {
    StudioPlan::factory()->create(['title' => 'Plain title']);
    $literal = StudioPlan::factory()->create(['title' => 'Up 50% off_now \\ deal']);

    expect(studioPlansPage($search))->toBe([$literal->id]);
})->with(['50%', 'off_now', '\\ deal']);

it('finds nothing for an id too long to be one, or a search much longer than any title', function () {
    StudioPlan::factory()->create(['title' => 'Plain title']);

    expect(studioPlansPage('#'.str_repeat('9', 40)))->toBe([])
        ->and(studioPlansPage(str_repeat('a', 500)))->toBe([]);
});

it('never finds a deleted author by what the account was called', function () {
    $persona = User::factory()->persona()->create(['username' => 'gone_owl', 'name' => 'Gone Owl', 'display_name' => 'Gone Owl']);
    StudioPlan::factory()->authoredBy($persona)->create(['title' => 'Unrelated']);
    $persona->forceFill(['status' => 'deleted', 'anonymized_at' => now()])->save();

    expect(studioPlansPage('gone_owl'))->toBe([])
        ->and(studioPlansPage('Gone Owl'))->toBe([]);
});

it('reads 25 plans a page, each with its author, the avatar and its photo loaded for the page', function () {
    StudioPlan::factory()->count(27)->withImage()->authoredBy(User::factory()->persona()->create())->create();

    $first = app(StudioPlansQuery::class)->paginate('', null, null, 1);

    expect($first->count())->toBe(25)
        ->and($first->total())->toBe(27)
        ->and($first->lastPage())->toBe(2)
        ->and(studioPlansPage(page: 2))->toHaveCount(2)
        ->and($first->first()->relationLoaded('author'))->toBeTrue()
        ->and($first->first()->author->relationLoaded('avatarAsset'))->toBeTrue()
        ->and($first->first()->relationLoaded('imageAsset'))->toBeTrue()
        ->and($first->first()->imageAsset->relationLoaded('variants'))->toBeTrue();
});

it('counts every plan, the drafts, and those with a photo and with an author', function () {
    StudioPlan::factory()->withImage()->authoredBy(User::factory()->persona()->create())->create();
    StudioPlan::factory()->withImage()->create();
    StudioPlan::factory()->status(StudioPlanStatus::Approved)->create();

    expect(app(StudioPlansQuery::class)->stats())->toBe(['total' => 3, 'drafts' => 2, 'withImage' => 2, 'withAuthor' => 1]);
});
