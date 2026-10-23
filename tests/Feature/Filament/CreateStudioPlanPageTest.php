<?php

use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\MediaKind;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\StudioPlans\Pages\CreateStudioPlan;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Livewire\Admin\StudioPlanDiscussionEditor;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\Tag;
use App\Models\User;
use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\GdImageIngestor;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;
use App\Services\Media\NormalizedImage;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;

/**
 * A new publication: nothing exists until Create draft, which works on an
 * empty editor too, or until the first photo, which makes one empty Draft to
 * be stored on and analysed straight away. The page goes on with that plan:
 * later photos replace its photo, what was typed stays typed and unsaved,
 * and Save draft writes it there. One plan however often either comes.
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

it('makes one empty draft for the first photo, stores the photo on it and analyses it, with no Save and nothing typed saved', function () {
    Queue::fake();
    Http::preventStrayRequests();
    configureStudioImageAnalysis();

    $page = Livewire::test(CreateStudioPlan::class)
        ->set('postTitle', 'Typed before the photo')
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 320, 240)])
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertSet('postTitle', 'Typed before the photo')
        ->assertSet('lockVersion', 2);

    $plan = StudioPlan::query()->sole();

    expect($page->get('planId'))->toBe($plan->id)
        ->and($plan)
        ->status->toBe(StudioPlanStatus::Draft)
        ->created_by_user_id->toBe($this->admin->id)
        ->title->toBeNull()
        ->lock_version->toBe(2)
        ->and($plan->imageAsset)
        ->kind->toBe(MediaKind::PostImage)
        ->owner_user_id->toBe($this->admin->id)
        ->width->toBe(320)
        ->and(StudioPlanImageAnalysis::query()->sole()->source_image_asset_id)->toBe($plan->image_asset_id)
        // Still unsaved: the draft is compared to what is saved, and the title is not.
        ->and($page->get('saved')['title'])->toBe('')
        ->and(Post::query()->withTrashed()->exists())->toBeFalse();
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);

    $page->assertViewHas('analysisPlanId', $plan->id)->assertViewHas('analysisImageId', $plan->image_asset_id);
});

it('goes on with the draft the first photo made: the next photo replaces its photo, and Save draft writes what was typed to it', function () {
    Queue::fake();
    $page = Livewire::test(CreateStudioPlan::class)->upload('photo', [UploadedFile::fake()->image('first.jpg', 64, 64)]);
    $plan = StudioPlan::query()->sole();
    $first = $plan->image_asset_id;

    $page->upload('photo', [UploadedFile::fake()->image('second.jpg', 80, 80)])
        ->assertHasNoErrors()
        ->assertSet('planId', $plan->id)
        ->assertSet('lockVersion', 3)
        ->set('postTitle', 'Typed after the photos')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(EditStudioPlan::getUrl(['record' => $plan]));

    expect(StudioPlan::query()->sole())
        ->id->toBe($plan->id)
        ->title->toBe('Typed after the photos')
        ->lock_version->toBe(4)
        ->image_asset_id->not->toBe($first)
        ->and(MediaAsset::withTrashed()->find($first)->trashed())->toBeTrue()
        ->and(session('rg-admin-toast'))->toBe(['message' => 'Typed after the photos saved', 'tone' => 'success']);
});

it('makes one draft however often a photo arrives, an upload reported twice stored once', function () {
    Queue::fake();
    FileUploadConfiguration::storage();
    $uploaded = (new FileUploadController)->validateAndStore([UploadedFile::fake()->image('once.jpg', 64, 64)], FileUploadConfiguration::disk());

    Livewire::test(CreateStudioPlan::class)
        ->call('_finishUpload', 'photo', $uploaded, false)
        ->call('_finishUpload', 'photo', $uploaded, false)
        ->upload('photo', [UploadedFile::fake()->image('again.jpg', 64, 64)])
        ->assertHasNoErrors();

    expect(StudioPlan::query()->count())->toBe(1)
        ->and(MediaAsset::withTrashed()->count())->toBe(2);
});

it('makes a draft for a photo imported from a direct link, through the safe importer, leaving no temporary file', function () {
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
        ->call('importImage', 'link')
        ->assertHasNoErrors()
        ->assertNoRedirect();

    $plan = StudioPlan::query()->sole();

    expect($plan->imageAsset)->width->toBe(400)->height->toBe(300)
        // The import link is the photo's, never the post's source.
        ->and($plan->source_url)->toBeNull()
        ->and(glob(sys_get_temp_dir().'/rg_import_*') ?: [])->toBe($temporary);
});

it('makes no draft for a photo refused before it is stored, or a link nothing comes from', function (Closure $choose) {
    Queue::fake();
    bindFakeHostResolver();

    $choose(Livewire::test(CreateStudioPlan::class))->assertNoRedirect();

    expect(StudioPlan::query()->exists())->toBeFalse()
        ->and(MediaAsset::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'not an image' => [fn ($page) => $page->set('photo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))->assertHasErrors('photo')],
    'a link that is no address' => [fn ($page) => $page->set('imageUrl', 'ftp://example.com/a.jpg')->call('importImage', 'link')->assertHasErrors('imageUrl')],
    'a link nothing comes from' => [fn ($page) => $page->set('imageUrl', 'https://unreachable.invalid/photo.jpg')->call('importImage', 'link')->assertHasErrors('imageUrl')],
]);

it('keeps the draft a photo made when the image is refused there, and stores the next photo on it', function () {
    Queue::fake();
    $failing = new class implements ImageIngestor
    {
        public bool $fail = true;

        public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
        {
            if ($this->fail) {
                throw ImageDecodeException::couldNotDecode();
            }

            return app(GdImageIngestor::class)->ingest($input, $policy);
        }
    };
    app()->instance(ImageIngestor::class, $failing);

    $page = Livewire::test(CreateStudioPlan::class)
        ->set('photo', UploadedFile::fake()->image('broken.jpg', 64, 64))
        ->assertHasErrors('photo');

    $plan = StudioPlan::query()->sole();
    expect($plan->image_asset_id)->toBeNull();

    $failing->fail = false;
    $page->set('photo', UploadedFile::fake()->image('fine.jpg', 64, 64))->assertHasNoErrors()->assertSet('planId', $plan->id);

    expect(StudioPlan::query()->sole()->image_asset_id)->not->toBeNull();
});

it('says so, and keeps what was typed, when the draft a photo made is changed or deleted before Save', function (Closure $change, string $message) {
    Queue::fake();
    $page = Livewire::test(CreateStudioPlan::class)->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)]);
    $change(StudioPlan::query()->sole());

    $page->set('postTitle', 'Mine')->call('save')->assertNoRedirect()->assertDispatched('rg-admin-toast', message: $message, tone: 'error')->assertSet('postTitle', 'Mine');
})->with([
    'changed' => [fn (StudioPlan $plan) => app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Theirs'), 2), 'Someone saved this draft meanwhile, so nothing was saved.'],
    'deleted' => [fn (StudioPlan $plan) => StudioPlan::query()->whereKey($plan->id)->delete(), 'This draft was deleted, so nothing was saved.'],
]);

it('renames Create draft to Save draft once a photo has made the draft', function () {
    $html = $this->get(StudioPlanResource::getUrl('create'))->assertOk()->getContent();

    expect($html)
        ->toContain('$store.adminEditor.saveLabel = $wire.planId ? &#039;Save draft&#039; : null')
        ->toContain('x-text="$store.adminEditor?.saveLabel || \'Create draft\'"');
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
]);

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

it('puts the empty draft back on Discard, the photo already saved staying', function () {
    Queue::fake();

    Livewire::test(CreateStudioPlan::class)
        ->upload('photo', [UploadedFile::fake()->image('a.jpg', 50, 50)])
        ->set('postTitle', 'Gone again')
        ->dispatch('discard-studio-plan')
        ->assertSet('postTitle', '')
        ->assertViewHas('shownPhoto', fn (?array $photo): bool => $photo !== null);

    expect(StudioPlan::query()->sole()->image_asset_id)->not->toBeNull();
});

it('starts with an active persona drawn as the author: no change, Discard back to it, and the first photo\'s draft made with it', function () {
    Queue::fake();
    $persona = User::factory()->persona()->create();
    User::factory()->persona()->create(['status' => UserStatus::Banned]);

    $page = Livewire::test(CreateStudioPlan::class)
        ->assertSet('startAuthorId', $persona->id)->assertSet('authorUserId', (string) $persona->id)
        ->assertSet('saved', fn (array $saved): bool => $saved['authorUserId'] === (string) $persona->id)
        ->call('clearAuthor')->assertSet('authorUserId', '')
        ->dispatch('discard-studio-plan')->assertSet('authorUserId', (string) $persona->id)
        ->upload('photo', [UploadedFile::fake()->image('a.jpg', 50, 50)])->assertHasNoErrors();

    expect(StudioPlan::query()->sole()->author_user_id)->toBe($persona->id);

    // With no active persona, no author.
    $persona->forceFill(['status' => UserStatus::Limited])->save();
    Livewire::test(CreateStudioPlan::class)->assertSet('startAuthorId', null)->assertSet('authorUserId', '');
});

it('picks another active persona at random as the author, and says when there is none', function () {
    [$anna, $boris] = User::factory()->persona()->count(2)->create()->all();
    $page = Livewire::test(CreateStudioPlan::class)->call('goToStep', 'post')->call('chooseAuthor', $anna->id)
        ->assertSeeHtml('wire:click="pickRandomAuthor"')
        ->call('pickRandomAuthor')->assertHasNoErrors()->assertSet('authorUserId', (string) $boris->id);

    $anna->forceFill(['status' => UserStatus::Banned])->save();

    $page->call('pickRandomAuthor')->assertHasErrors(['authorUserId'])->assertSet('authorUserId', (string) $boris->id);
});

it('opens the editor a save here makes on the step it was on, generating there when Generate tree asked for the save — once', function () {
    Queue::fake();
    $page = Livewire::test(CreateStudioPlan::class)
        ->upload('photo', [UploadedFile::fake()->image('a.jpg', 50, 50)])
        ->call('goToStep', 'discussion')->set('postTitle', 'A harbour at dawn')
        ->dispatch('save-studio-plan', then: 'generate');
    $plan = StudioPlan::query()->sole();

    $page->assertRedirect(EditStudioPlan::getUrl(['record' => $plan->id]));
    expect($plan->title)->toBe('A harbour at dawn')
        ->and(session(CreateStudioPlan::RESUME))->toBe(['plan' => $plan->id, 'step' => 'discussion', 'postTab' => 'texts', 'generate' => true]);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->assertSet('step', 'discussion')
        ->assertDispatched(StudioPlanDiscussionEditor::GENERATE_EVENT, planId: $plan->id);

    // Read once: the editor opened again stays on its first step and generates nothing.
    session()->forget(CreateStudioPlan::RESUME);
    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->assertSet('step', 'image')
        ->assertNotDispatched(StudioPlanDiscussionEditor::GENERATE_EVENT);
});
