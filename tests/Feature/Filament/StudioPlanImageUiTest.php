<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\MediaKind;
use App\Enums\MediaVariantName;
use App\Enums\StudioImageAnalysisStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Jobs\GenerateMediaVariantsJob;
use App\Jobs\Studio\AnalyzeStudioPlanImageJob;
use App\Livewire\Admin\StudioPlanImageAnalysisCard;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\ProjectSettings;
use App\Models\StudioPlan;
use App\Models\StudioPlanImageAnalysis;
use App\Models\User;
use App\Services\Media\Exceptions\ImageDecodeException;
use App\Services\Media\ImageIngestor;
use App\Services\Media\ImageIngestPolicy;
use App\Services\Media\ImageInput;
use App\Services\Media\NormalizedImage;
use App\Support\Import\ImportHttpTransport;
use App\Support\Import\ImportTransportResponse;
use App\Support\Settings\ProjectSettingsManager;
use Database\Factories\StudioPlanImageAnalysisFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\FileUploadController;
use Livewire\Livewire;

/**
 * The editor's Image step: a photo from the computer, a direct link or a
 * social post is stored on the plan the moment it arrives — no Save — through
 * the plan's own action, and its analysis is asked for at once; replaced or
 * removed, the old photo goes to the media lifecycle and its analysis out of
 * view. The texts typed stay as typed and unsaved, the next Save is no
 * conflict with the photo, an upload reported twice is stored once, and an
 * old editor never puts a photo over a newer one.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    Http::preventStrayRequests();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('stores a JPEG, PNG or WebP photo the moment it is uploaded, owned by the administrator, with no Save', function (string $name, string $mime) {
    $plan = StudioPlan::factory()->create(['title' => 'Saved title']);

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->upload('photo', [UploadedFile::fake()->image($name, 320, 200)])
        ->assertHasNoErrors()
        ->assertSet('photo', null)
        ->assertSet('lockVersion', 2)
        ->assertDispatched('rg-studio-photo-kept')
        ->assertNotDispatched('rg-admin-toast');

    $asset = $plan->fresh()->imageAsset;

    expect($asset)->kind->toBe(MediaKind::PostImage)->mime_type->toBe($mime)->owner_user_id->toBe($this->admin->id)
        ->and($plan->fresh()->only(['title', 'lock_version']))->toBe(['title' => 'Saved title', 'lock_version' => 2])
        ->and($page->viewData('shownPhoto'))->toMatchArray(['name' => $name, 'details' => ['320 × 200', $page->viewData('shownPhoto')['details'][1], $mime]])
        ->and($page->viewData('shownPhoto')['url'])->not->toBeNull()
        ->and($page->viewData('analysisImageId'))->toBe($asset->id);

    // The photo is no change to the draft: nothing is left unsaved for it.
    expect($page->get('saved'))->not->toHaveKeys(['photo', 'imageUrl', 'removeImage']);
    Queue::assertPushed(GenerateMediaVariantsJob::class, 1);
})->with([
    'a JPEG' => ['harbour.jpg', 'image/jpeg'],
    'a PNG' => ['harbour.png', 'image/png'],
    'a WebP' => ['harbour.webp', 'image/webp'],
]);

it('refuses a file too large or of a kind the site does not take as soon as it is chosen, storing and analysing nothing', function (Closure $file, string $message) {
    configureStudioImageAnalysis();
    config(['uploads.images.max_kilobytes' => 1]);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('photo', $file())
        ->assertHasErrors('photo')
        ->assertSee('Upload failed.')
        ->assertSee($message)
        ->assertSet('photo', null)
        ->assertSet('lockVersion', 1);

    expect($plan->fresh()->image_asset_id)->toBeNull()
        ->and(MediaAsset::query()->exists())->toBeFalse()
        ->and(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNothingPushed();
})->with([
    'too large' => [fn () => UploadedFile::fake()->image('big.jpg', 1600, 1600), 'Choose an image of 1 KB or less.'],
    'a GIF' => [fn () => UploadedFile::fake()->image('moving.gif', 20, 20), 'Choose a JPEG, PNG or WebP image.'],
    'not an image' => [fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'Choose a JPEG, PNG or WebP image.'],
]);

it('says by the drop zone when the image cannot be read, storing and analysing nothing', function () {
    configureStudioImageAnalysis();
    app()->instance(ImageIngestor::class, new class implements ImageIngestor
    {
        public function ingest(ImageInput $input, ImageIngestPolicy $policy): NormalizedImage
        {
            throw ImageDecodeException::couldNotDecode();
        }
    });
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('photo', UploadedFile::fake()->image('broken.jpg', 64, 64))
        ->assertHasErrors(['photo' => 'This image can’t be used. Choose another JPEG, PNG or WebP image.'])
        ->assertSet('lockVersion', 1)
        ->assertNotDispatched('rg-studio-photo-kept');

    expect($plan->fresh()->image_asset_id)->toBeNull()
        ->and(MediaAsset::query()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('asks for one analysis of the photo it stores, with nothing typed saved for it, and the next Save no conflict', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create(['title' => 'Saved title']);

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'Typed, not saved yet')
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])
        ->assertHasNoErrors()
        ->assertSet('postTitle', 'Typed, not saved yet')
        ->assertSet('lockVersion', 2)
        ->assertSet('stale', false);

    $analysis = StudioPlanImageAnalysis::query()->sole();

    expect($analysis)->status->toBe(StudioImageAnalysisStatus::Queued)->source_image_asset_id->toBe($plan->fresh()->image_asset_id)
        // The title is still the saved one, and still counts as unsaved.
        ->and($plan->fresh()->title)->toBe('Saved title')
        ->and($page->get('saved')['title'])->toBe('Saved title');
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
    Http::assertNothingSent();

    $page->call('save')->assertHasNoErrors()->assertSet('stale', false)->assertDispatched('rg-admin-toast', message: 'Draft saved', tone: 'success');

    expect($plan->fresh())->title->toBe('Typed, not saved yet')->lock_version->toBe(3)->image_asset_id->toBe($analysis->source_image_asset_id);
});

it('shows the new photo\'s analysis card at once, waiting for the analysis', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)]);

    Livewire::test(StudioPlanImageAnalysisCard::class, ['planId' => $plan->id, 'imageAssetId' => $page->viewData('analysisImageId')])
        ->assertSee('Waiting for analysis…')
        ->assertSee('Analysing')
        ->assertSee('harbour.jpg')
        ->assertSeeHtml('wire:poll.'.StudioPlanImageAnalysisCard::POLL_SECONDS.'s.visible');
});

it('replaces the photo with the next one chosen, releasing the old one and analysing the new one alone', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create();
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->upload('photo', [UploadedFile::fake()->image('first.jpg', 64, 64)]);
    $first = $plan->fresh()->imageAsset;
    StudioPlanImageAnalysis::query()->sole()->forceFill([
        'status' => StudioImageAnalysisStatus::Completed,
        'result' => StudioPlanImageAnalysisFactory::description(),
        'finished_at' => now(),
    ])->save();

    $page->upload('photo', [UploadedFile::fake()->image('second.jpg', 80, 80)])
        ->assertHasNoErrors()
        ->assertSet('lockVersion', 3)
        ->assertSet('replacedPhoto', 'first.jpg')
        ->assertSee('Replaced first.jpg. The analysis runs again for the new photo.');

    $second = $plan->fresh()->imageAsset;

    expect($second->id)->not->toBe($first->id)
        ->and(MediaAsset::withTrashed()->find($first->id)->trashed())->toBeTrue()
        // The old photo's result is gone with it: the attempt is the new photo's.
        ->and(StudioPlanImageAnalysis::query()->sole())
        ->source_image_asset_id->toBe($second->id)
        ->status->toBe(StudioImageAnalysisStatus::Queued)
        ->result->toBeNull();
    Storage::disk('public')->assertExists($first->path);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 2);
});

it('stores an upload reported twice once, and analyses it once', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create();
    // What the browser's upload leaves behind: one stored temporary file, by its signed name.
    FileUploadConfiguration::storage();
    $uploaded = (new FileUploadController)->validateAndStore([UploadedFile::fake()->image('once.jpg', 64, 64)], FileUploadConfiguration::disk());

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('_finishUpload', 'photo', $uploaded, false)
        ->call('_finishUpload', 'photo', $uploaded, false)
        ->assertHasNoErrors()
        ->assertSet('lockVersion', 2);

    expect(MediaAsset::withTrashed()->count())->toBe(1)
        ->and($plan->fresh()->lock_version)->toBe(2);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('keeps the photo saved when OpenAI fails, saying so in the analysis alone', function () {
    configureStudioImageAnalysis();
    // Only the photo's variants are held back; the analysis runs as it is pushed.
    Queue::fake([GenerateMediaVariantsJob::class]);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Server error']], 500)]);
    $plan = StudioPlan::factory()->create();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])
        ->assertHasNoErrors()
        ->assertNotDispatched('rg-admin-toast');

    expect($plan->fresh()->image_asset_id)->not->toBeNull()
        ->and(StudioPlanImageAnalysis::query()->sole()->status)->toBe(StudioImageAnalysisStatus::Failed);
    Http::assertSentCount(1);

    Livewire::test(StudioPlanImageAnalysisCard::class, ['planId' => $plan->id, 'imageAssetId' => $page->viewData('analysisImageId')])
        ->assertSee('OpenAI could not be reached. Try again later.')
        ->assertSee('Try again');
});

it('stores the photo with analysis not configured, asking for none', function () {
    configureStudioImageAnalysis(['api_key' => null]);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->upload('photo', [UploadedFile::fake()->image('harbour.jpg', 64, 64)])
        ->assertHasNoErrors()
        ->assertSet('lockVersion', 2)
        ->assertDontSee('The analysis runs again');

    expect($plan->fresh()->image_asset_id)->not->toBeNull()
        ->and(StudioPlanImageAnalysis::query()->exists())->toBeFalse();
    Queue::assertNotPushed(AnalyzeStudioPlanImageJob::class);
});

it('lets an old editor put no photo over a newer one, nor take it for its own', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create();
    $old = Livewire::test(EditStudioPlan::class, ['record' => $plan->id]);
    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->upload('photo', [UploadedFile::fake()->image('newer.jpg', 64, 64)]);
    $newer = $plan->fresh()->image_asset_id;

    // The older upload arrives last, from an editor drawn before the newer one.
    $old->upload('photo', [UploadedFile::fake()->image('older.jpg', 64, 64)])
        ->assertSet('stale', true)
        ->assertSet('lockVersion', 1)
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this draft after you opened it, so the photo wasn’t saved.', tone: 'error');

    expect($plan->fresh()->image_asset_id)->toBe($newer)
        ->and(MediaAsset::query()->count())->toBe(1)
        ->and(StudioPlanImageAnalysis::query()->sole()->source_image_asset_id)->toBe($newer);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('keeps another administrator\'s saved texts when a photo comes from an editor drawn before them', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Before']);
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id]);
    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Theirs'), 1);

    $page->upload('photo', [UploadedFile::fake()->image('mine.jpg', 64, 64)])->assertSet('stale', true);

    expect($plan->fresh())->title->toBe('Theirs')->image_asset_id->toBeNull()->lock_version->toBe(2);
});

it('takes the photo off at once, releasing it to the media lifecycle', function () {
    configureStudioImageAnalysis();
    $plan = StudioPlan::factory()->create();
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->upload('photo', [UploadedFile::fake()->image('photo.jpg', 64, 64)]);
    $asset = $plan->fresh()->imageAsset;

    $page->call('removePhoto')
        ->assertSet('lockVersion', 3)
        ->assertDispatched('rg-admin-toast', message: 'Photo removed from this draft', tone: 'info')
        ->assertViewHas('shownPhoto', null)
        ->assertViewHas('analysisImageId', null)
        ->assertSee('Drop a photo here');

    expect($plan->fresh()->image_asset_id)->toBeNull()
        ->and(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($asset->path);
});

it('shows the saved photo at a variant\'s size when one is on disk', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $variant = MediaVariant::factory()->named(MediaVariantName::PostFeed1280)->create(['media_asset_id' => $plan->image_asset_id, 'disk' => 'public', 'path' => 'post-images/feed-1280.webp']);
    Storage::disk('public')->put($variant->path, 'smaller');

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertViewHas('shownPhoto', fn (array $photo): bool => str_ends_with((string) $photo['url'], 'post-images/feed-1280.webp'))
        ->assertSee('Replace')
        ->assertSeeHtml('aria-label="Remove from draft"');
});

it('hands a dropped file and a picked one to the same upload, and saves nothing on Save for either', function () {
    $html = Livewire::test(EditStudioPlan::class, ['record' => StudioPlan::factory()->create()->id])->html();

    expect($html)
        ->toContain('x-on:drop.prevent="send($event.dataTransfer?.files?.[0])"')
        ->toContain('x-on:change="send($event.target.files[0]); $event.target.value = \'\'"')
        ->toContain("this.\$wire.upload('photo', file")
        // Nothing binds the file to the draft: the upload is the only way in.
        ->not->toContain('wire:model="photo"')
        ->not->toContain('Save the draft first');
});

it('imports a photo from a direct link without Save, through the safe importer, leaving no temporary file and the post\'s source untouched', function () {
    configureStudioImageAnalysis();
    bindFakeHostResolver();
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/png'], jpegMarkerBytes(300, 200)),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);
    $plan = StudioPlan::factory()->create(['source_url' => 'https://example.com/story']);
    $temporary = glob(sys_get_temp_dir().'/rg_import_*') ?: [];

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageSource', 'link')
        ->set('imageUrl', ' https://example.com/photo.png ')
        ->call('importImage', 'link')
        ->assertHasNoErrors()
        ->assertSet('imageUrl', '')
        ->assertSet('lockVersion', 2);

    expect($plan->fresh()->imageAsset)->width->toBe(300)->height->toBe(200)
        ->and($plan->fresh()->source_url)->toBe('https://example.com/story')
        ->and(glob(sys_get_temp_dir().'/rg_import_*') ?: [])->toBe($temporary)
        ->and(StudioPlanImageAnalysis::query()->sole()->source_image_asset_id)->toBe($plan->fresh()->image_asset_id);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);

    // The same address again — pasted twice, or sent again — is not fetched again.
    $page->set('imageUrl', 'https://example.com/photo.png')->call('importImage', 'link')->assertHasNoErrors();

    expect($transport->calls)->toHaveCount(1)
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('imports nothing from a link that is no web address', function () {
    $importer = $this->mock(StoreImportedImageAction::class);
    $importer->shouldNotReceive('download');
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageUrl', 'ftp://example.com/a.jpg')
        ->call('importImage', 'link')
        ->assertHasErrors('imageUrl')
        ->assertSee('Enter a full web address to an image, starting with https://.');

    expect($plan->fresh()->image_asset_id)->toBeNull();
});

it('cleans the imported file up even when the image is refused, says so by the link, and asks for no analysis', function () {
    configureStudioImageAnalysis();
    $file = UploadedFile::fake()->createWithContent('photo.jpg', 'not an image');
    $importer = $this->mock(StoreImportedImageAction::class);
    $importer->shouldReceive('download')->once()->with('https://example.com/photo.jpg')->andReturn($file);
    $importer->shouldReceive('cleanup')->once()->with($file);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageUrl', 'https://example.com/photo.jpg')
        ->call('importImage', 'link')
        ->assertHasErrors(['imageUrl' => 'The file at this link isn’t an image the site takes. Use a JPEG, PNG or WebP image.'])
        ->assertSet('imageUrl', 'https://example.com/photo.jpg')
        ->assertSee('Try again');

    expect($plan->fresh()->image_asset_id)->toBeNull();
    Queue::assertNotPushed(AnalyzeStudioPlanImageJob::class);
});

it('says by the link when nothing can be downloaded from it, and tries the same address again on Try again', function () {
    bindFakeHostResolver();
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(120, 90)),
    ]));
    $plan = StudioPlan::factory()->create();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageUrl', 'https://nowhere.invalid/photo.jpg')
        ->call('importImage', 'link')
        ->assertHasErrors(['imageUrl' => 'No image could be downloaded from this link. Use a direct link to a JPEG, PNG or WebP file.'])
        ->assertSet('imageSource', 'link')
        ->assertSet('step', 'image');

    expect($plan->fresh()->image_asset_id)->toBeNull();

    // Sent again unchanged, it is not fetched again; Try again fetches it.
    $page->call('importImage', 'link')->assertHasNoErrors();
    $page->set('imageUrl', 'https://example.com/photo.jpg')->call('retryImport')->assertHasNoErrors();

    expect($plan->fresh()->imageAsset)->width->toBe(120);
});

it('imports the image of a public post without Save, and analyses it', function () {
    configureStudioImageAnalysis();
    bindFakeHostResolver();
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'text/html'], '<head><meta property="og:image" content="https://cdn.example.com/post.jpg"></head>'),
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(400, 300)),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageSource', 'post')
        ->assertSee('From social post')
        ->assertSee('Instagram')
        ->set('postUrl', 'https://example.com/story')
        ->call('importImage', 'post')
        ->assertHasNoErrors()
        ->assertSet('postUrl', '')
        ->assertSet('lockVersion', 2);

    expect($plan->fresh()->imageAsset)->width->toBe(400)->height->toBe(300)
        ->and(collect($transport->calls)->map(fn ($target) => $target->url)->all())->toBe(['https://example.com/story', 'https://cdn.example.com/post.jpg']);
    Queue::assertPushed(AnalyzeStudioPlanImageJob::class, 1);
});

it('says which site a post link is for', function () {
    Livewire::test(EditStudioPlan::class, ['record' => StudioPlan::factory()->create()->id])
        ->set('imageSource', 'post')
        ->set('postUrl', 'https://www.instagram.com/p/C0ffee/')
        ->assertViewHas('detectedPlatform', 'Instagram')
        ->assertSee('Detected: Instagram post.')
        ->set('postUrl', 'https://example.com/story')
        ->assertViewHas('detectedPlatform', null);
});

it('says so by the post link when the post shows no image, or importing from links is switched off', function (Closure $arrange, string $message) {
    bindFakeHostResolver();
    $arrange();
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postUrl', 'https://example.com/story')
        ->call('importImage', 'post')
        ->assertHasErrors(['postUrl' => $message]);

    expect($plan->fresh()->image_asset_id)->toBeNull();
    Queue::assertNothingPushed();
})->with([
    'no image' => [fn () => app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'text/html'], '<head><title>Words only</title></head>'),
    ])), 'No image was found in this post. It may be private, or the site may not share it.'],
    'imports switched off' => [function () {
        ProjectSettings::factory()->create(['feature_flags' => ['allow_url_imports' => false]]);
        app(ProjectSettingsManager::class)->flush();
    }, 'Importing from links is switched off in Project settings.'],
]);

it('keeps the source on screen apart: switching it clears the other one\'s error', function () {
    Livewire::test(EditStudioPlan::class, ['record' => StudioPlan::factory()->create()->id])
        ->set('imageUrl', 'ftp://example.com/a.jpg')
        ->call('importImage', 'link')
        ->assertHasErrors('imageUrl')
        ->set('imageSource', 'upload')
        ->assertHasNoErrors()
        ->set('imageSource', 'anything else')
        ->assertSet('imageSource', 'upload');
});

it('imports a link it kept once more after the photo was removed or replaced by a file, never while it is still the photo', function (Closure $between) {
    bindFakeHostResolver();
    $transport = new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(300, 200)),
        new ImportTransportResponse(200, ['content-type' => 'image/jpeg'], jpegMarkerBytes(300, 200)),
    ]);
    app()->instance(ImportHttpTransport::class, $transport);
    $plan = StudioPlan::factory()->create();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageUrl', 'https://example.com/photo.jpg')
        ->call('importImage', 'link')
        ->set('imageUrl', 'https://example.com/photo.jpg')
        ->call('importImage', 'link');

    expect($transport->calls)->toHaveCount(1);

    $between($page)->assertSet('importedFrom', '');
    $page->set('imageUrl', 'https://example.com/photo.jpg')->call('importImage', 'link')->assertHasNoErrors();

    expect($transport->calls)->toHaveCount(2)
        ->and($plan->fresh()->imageAsset)->width->toBe(300);
})->with([
    'removed' => [fn ($page) => $page->call('removePhoto')],
    'replaced by a file' => [fn ($page) => $page->upload('photo', [UploadedFile::fake()->image('file.jpg', 64, 64)])],
]);

it('forgets in the browser what it sent once a photo is kept or removed', function () {
    expect(Livewire::test(EditStudioPlan::class, ['record' => StudioPlan::factory()->create()->id])->html())
        ->toContain('x-on:rg-studio-photo-kept.window="replacing = false; imported = { link: \'\', post: \'\' }"');
});
