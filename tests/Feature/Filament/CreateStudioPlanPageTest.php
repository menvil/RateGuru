<?php

use App\Enums\MediaKind;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\Tag;
use App\Models\User;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * A new publication: nothing exists until Create draft, which works on an
 * empty editor too; one plan however often it is sent; the photo given to
 * it right after — and a photo that fails says so, keeping the plan, with no
 * second one on a retry.
 */
beforeEach(function () {
    Storage::fake('public');
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('draws a new publication with Create draft in the top bar, which waits for no change', function () {
    $html = $this->get(StudioPlanResource::getUrl('create'))->assertOk()->getContent();

    expect($html)
        ->toContain('New publication')
        ->toContain('Create draft')
        ->toContain('Saving the draft publishes nothing.')
        // Save waits only while the page says it cannot save.
        ->toContain('x-bind:disabled="$store.adminEditor?.blocked"')
        ->and(StudioPlan::query()->exists())->toBeFalse();
});

it('creates an empty draft from an untouched editor, and opens its editor', function () {
    Livewire::test(CreateStudioPlan::class)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(EditStudioPlan::getUrl(['record' => StudioPlan::query()->sole()]));

    expect(StudioPlan::query()->sole())
        ->status->toBe(StudioPlanStatus::Draft)
        ->created_by_user_id->toBe($this->admin->id)
        ->title->toBeNull()
        ->author_user_id->toBeNull()
        ->image_asset_id->toBeNull()
        ->lock_version->toBe(1)
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Untitled draft #'.StudioPlan::query()->sole()->id.' created', 'tone' => 'success']);
});

it('creates a draft with what the editor holds', function () {
    $persona = User::factory()->persona()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $option = RatingOption::factory()->create();

    Livewire::test(CreateStudioPlan::class)
        ->set('postTitle', '  A quiet harbour ')
        ->set('postDescription', 'Boats at dawn.')
        ->set('sourceUrl', 'https://example.com/harbour')
        ->call('chooseAuthor', $persona->id)
        ->set('categoryId', (string) $category->id)
        ->call('addTag', $tag->id)
        ->set("authorAnswers.{$option->rating_group_id}", (string) $option->id)
        ->set('publicationMode', 'at_date')
        ->set('publishAt', '2031-02-03T04:05')
        ->call('save')
        ->assertHasNoErrors();

    expect(StudioPlan::query()->sole())
        ->title->toBe('A quiet harbour')
        ->description->toBe('Boats at dawn.')
        ->source_url->toBe('https://example.com/harbour')
        ->author_user_id->toBe($persona->id)
        ->category_id->toBe($category->id)
        ->tag_ids->toBe([$tag->id])
        ->author_answer_option_ids->toBe([$option->id])
        ->publication_mode->toBe(StudioPlanPublicationMode::AtDate)
        ->and(StudioPlan::query()->sole()->publish_at->toDateTimeString())->toBe('2031-02-03 04:05:00');
});

it('creates a draft with a photo, owned by the administrator, and opens its editor', function () {
    Queue::fake();

    Livewire::test(CreateStudioPlan::class)
        ->set('photo', UploadedFile::fake()->image('harbour.jpg', 320, 240))
        ->call('save')
        ->assertHasNoErrors();

    $plan = StudioPlan::query()->sole();

    expect($plan->imageAsset)
        ->kind->toBe(MediaKind::PostImage)
        ->owner_user_id->toBe($this->admin->id)
        ->width->toBe(320)
        ->and($plan->lock_version)->toBe(2);
});

it('creates a draft with a photo imported from a direct link, through the safe importer, leaving no temporary file', function () {
    Queue::fake();
    Http::preventStrayRequests();
    bindFakeHostResolver();
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(400, 300)),
    ]));
    $temporary = glob(sys_get_temp_dir().'/rg_import_*') ?: [];

    Livewire::test(CreateStudioPlan::class)
        ->set('imageSource', 'link')
        ->set('imageUrl', 'https://example.com/harbour.jpg')
        ->call('save')
        ->assertHasNoErrors();

    $plan = StudioPlan::query()->sole();

    expect($plan->imageAsset)->width->toBe(400)->height->toBe(300)
        // The import link is the photo's, never the post's source.
        ->and($plan->source_url)->toBeNull()
        ->and(glob(sys_get_temp_dir().'/rg_import_*') ?: [])->toBe($temporary);
});

it('creates nothing when what the editor holds is wrong, and shows the step to fix', function (Closure $draft, string $field, string $step) {
    $page = $draft(Livewire::test(CreateStudioPlan::class)->call('goToStep', 'publication'))->call('save');

    $page->assertHasErrors($field)->assertSet('step', $step)->assertNoRedirect();

    expect(StudioPlan::query()->exists())->toBeFalse();
})->with([
    'a title too short' => [fn ($page) => $page->set('postTitle', 'ab'), 'title', 'post'],
    'a description too long' => [fn ($page) => $page->set('postDescription', str_repeat('a', 2001)), 'description', 'post'],
    'a source that is no address' => [fn ($page) => $page->set('sourceUrl', 'not an address'), 'sourceUrl', 'post'],
    'an author that is no persona' => [fn ($page) => $page->set('authorUserId', (string) User::factory()->create()->id), 'authorUserId', 'post'],
    'a forged author' => [fn ($page) => $page->set('authorUserId', 'admin'), 'authorUserId', 'post'],
    'a date out of range' => [fn ($page) => $page->set('publicationMode', 'at_date')->set('publishAt', '1960-01-01T00:00'), 'publishAt', 'publication'],
    'a date that is no date' => [fn ($page) => $page->set('publicationMode', 'at_date')->set('publishAt', 'tomorrow'), 'publishAt', 'publication'],
    'a link that is no address' => [fn ($page) => $page->set('imageSource', 'link')->set('imageUrl', 'ftp://example.com/a.jpg'), 'imageUrl', 'image'],
]);

it('keeps the draft it made when the photo fails, says so, and makes no second one when Save comes again', function () {
    // A host nothing resolves: the importer refuses it before any request.
    bindFakeHostResolver();

    $page = Livewire::test(CreateStudioPlan::class)
        ->set('postTitle', 'Kept')
        ->set('imageSource', 'link')
        ->set('imageUrl', 'https://unreachable.invalid/photo.jpg')
        ->call('save');

    $plan = StudioPlan::query()->sole();

    $page->assertRedirect(EditStudioPlan::getUrl(['record' => $plan]));
    expect($plan)->title->toBe('Kept')->image_asset_id->toBeNull()
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Kept created, but its photo wasn’t saved. Choose it again.', 'tone' => 'error']);

    $page->call('save')->assertRedirect(EditStudioPlan::getUrl(['record' => $plan]));

    expect(StudioPlan::query()->count())->toBe(1);
});

it('makes one plan however often Save reaches the page', function () {
    $page = Livewire::test(CreateStudioPlan::class)->set('postTitle', 'Once');

    $page->call('save');
    $page->call('save');
    $page->dispatch('save-studio-plan');

    expect(StudioPlan::query()->count())->toBe(1);
});

it('publishes, queues and asks AI for nothing', function () {
    Queue::fake();
    Http::preventStrayRequests();

    Livewire::test(CreateStudioPlan::class)
        ->set('postTitle', 'Just a draft')
        ->set('publicationMode', 'now')
        ->call('save');

    expect(Post::query()->withTrashed()->exists())->toBeFalse()
        ->and(MediaAsset::query()->withTrashed()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

it('puts the empty draft back on Discard', function () {
    Livewire::test(CreateStudioPlan::class)
        ->set('postTitle', 'Gone again')
        ->set('photo', UploadedFile::fake()->image('a.jpg', 50, 50))
        ->dispatch('discard-studio-plan')
        ->assertSet('postTitle', '')
        ->assertSet('photo', null);
});
