<?php

use App\Actions\Import\StoreImportedImageAction;
use App\Enums\MediaKind;
use App\Enums\MediaVariantName;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\StudioPlan;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The editor's Image step: a photo from the computer or from a direct link,
 * checked when chosen and stored only on Save through the plan's own
 * actions; replaced or taken off, the old photo released to the media
 * lifecycle; a save of text and photo never in conflict with itself, an old
 * editor never over a newer photo, and a photo that fails never costing the
 * rest of the save.
 */
beforeEach(function () {
    Storage::fake('public');
    Queue::fake();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('stores a JPEG, PNG or WebP photo on Save, owned by the administrator, and shows it', function (string $name, string $mime) {
    $plan = StudioPlan::factory()->create();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('photo', UploadedFile::fake()->image($name, 320, 200))
        ->assertHasNoErrors()
        ->assertViewHas('shownPhoto', fn (array $photo): bool => $photo['state'] === 'chosen' && $photo['name'] === $name);

    // Chosen, not stored.
    expect(MediaAsset::query()->exists())->toBeFalse();

    $page->call('save')->assertHasNoErrors()->assertSet('lockVersion', 2)->assertSet('photo', null);

    $asset = $plan->fresh()->imageAsset;

    expect($asset)->kind->toBe(MediaKind::PostImage)->mime_type->toBe($mime)->owner_user_id->toBe($this->admin->id)
        ->and($page->viewData('shownPhoto'))->toMatchArray(['state' => 'saved', 'details' => ['320 × 200', $page->viewData('shownPhoto')['details'][1], $mime]])
        ->and($page->viewData('shownPhoto')['url'])->not->toBeNull();
})->with([
    'a JPEG' => ['harbour.jpg', 'image/jpeg'],
    'a PNG' => ['harbour.png', 'image/png'],
    'a WebP' => ['harbour.webp', 'image/webp'],
]);

it('refuses a file too large or of a kind the site does not take as soon as it is chosen', function (Closure $file, string $message) {
    config(['uploads.images.max_kilobytes' => 1]);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('photo', $file())
        ->assertHasErrors('photo')
        ->assertSee($message)
        ->call('save');

    expect($plan->fresh()->image_asset_id)->toBeNull()
        ->and(MediaAsset::query()->exists())->toBeFalse();
})->with([
    'too large' => [fn () => UploadedFile::fake()->image('big.jpg', 1600, 1600), 'Choose an image of 1 KB or less.'],
    'a GIF' => [fn () => UploadedFile::fake()->image('moving.gif', 20, 20), 'Choose a JPEG, PNG or WebP image.'],
    'not an image' => [fn () => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'), 'Choose a JPEG, PNG or WebP image.'],
]);

it('imports a photo from a direct link through the safe importer on Save, leaving no temporary file and the post\'s source untouched', function () {
    bindFakeHostResolver();
    app()->instance(ImportHttpTransport::class, new ScriptedImportHttpTransport([
        new ImportTransportResponse(200, ['content-type' => 'image/png'], jpegMarkerBytes(300, 200)),
    ]));
    $plan = StudioPlan::factory()->create(['source_url' => 'https://example.com/story']);
    $temporary = glob(sys_get_temp_dir().'/rg_import_*') ?: [];

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageSource', 'link')
        ->set('imageUrl', 'https://example.com/photo.png')
        ->assertSee('The image at this link is downloaded, checked and stored when you save.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('imageUrl', '');

    expect($plan->fresh()->imageAsset)->width->toBe(300)->height->toBe(200)
        ->and($plan->fresh()->source_url)->toBe('https://example.com/story')
        ->and(glob(sys_get_temp_dir().'/rg_import_*') ?: [])->toBe($temporary);
});

it('cleans the imported file up even when the image is refused, and says so by the link', function () {
    $file = UploadedFile::fake()->createWithContent('photo.jpg', 'not an image');
    $importer = $this->mock(StoreImportedImageAction::class);
    $importer->shouldReceive('download')->once()->with('https://example.com/photo.jpg')->andReturn($file);
    $importer->shouldReceive('cleanup')->once()->with($file);
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageSource', 'link')
        ->set('imageUrl', 'https://example.com/photo.jpg')
        ->call('save')
        ->assertHasErrors('imageUrl')
        ->assertDispatched('rg-admin-toast', message: 'The photo wasn’t saved.', tone: 'error')
        ->assertSet('imageUrl', 'https://example.com/photo.jpg');

    expect($plan->fresh()->image_asset_id)->toBeNull();
});

it('says by the link when nothing can be downloaded from it', function () {
    bindFakeHostResolver();
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('imageSource', 'link')
        ->set('imageUrl', 'https://nowhere.invalid/photo.jpg')
        ->call('save')
        ->assertHasErrors(['imageUrl' => 'No image could be downloaded from this link. Use a direct link to a JPEG, PNG or WebP file.']);
});

it('replaces the photo, the old one released to the media lifecycle and its file kept', function () {
    $plan = StudioPlan::factory()->create();
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('photo', UploadedFile::fake()->image('first.jpg', 64, 64))->call('save');
    $first = $plan->fresh()->imageAsset;

    $page->set('photo', UploadedFile::fake()->image('second.jpg', 80, 80))->call('save')->assertHasNoErrors()->assertSet('lockVersion', 3);

    expect($plan->fresh()->image_asset_id)->not->toBe($first->id)
        ->and(MediaAsset::withTrashed()->find($first->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($first->path);
});

it('takes the photo off on Save only, releasing it to the media lifecycle', function () {
    $plan = StudioPlan::factory()->create();
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('photo', UploadedFile::fake()->image('photo.jpg', 64, 64))->call('save');
    $asset = $plan->fresh()->imageAsset;

    $page->call('removeSavedImage')
        ->assertSet('removeImage', true)
        ->assertSee('The photo is taken off this draft when you save.');

    expect($plan->fresh()->image_asset_id)->toBe($asset->id);

    $page->call('save')->assertHasNoErrors()->assertSet('removeImage', false);

    expect($plan->fresh()->image_asset_id)->toBeNull()
        ->and(MediaAsset::withTrashed()->find($asset->id)->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($asset->path);
});

it('shows the saved photo at a variant\'s size when one is on disk', function () {
    $plan = StudioPlan::factory()->withImage()->create();
    $variant = MediaVariant::factory()->named(MediaVariantName::PostFeed1280)->create(['media_asset_id' => $plan->image_asset_id, 'disk' => 'public', 'path' => 'post-images/feed-1280.webp']);
    Storage::disk('public')->put($variant->path, 'smaller');

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertViewHas('shownPhoto', fn (array $photo): bool => str_ends_with((string) $photo['url'], 'post-images/feed-1280.webp'));
});

it('saves text and photo together without a conflict with itself', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Before']);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'After')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 64, 64))
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('stale', false)
        ->assertDispatched('rg-admin-toast', message: 'Draft saved', tone: 'success')
        ->assertSet('lockVersion', 3);

    expect($plan->fresh())->title->toBe('After')->image_asset_id->not->toBeNull()->lock_version->toBe(3);
});

it('lets an old editor put no photo over a newer one', function () {
    $plan = StudioPlan::factory()->create();
    $old = Livewire::test(EditStudioPlan::class, ['record' => $plan->id]);
    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('photo', UploadedFile::fake()->image('newer.jpg', 64, 64))->call('save');
    $newer = $plan->fresh()->image_asset_id;

    $old->set('photo', UploadedFile::fake()->image('older.jpg', 64, 64))
        ->call('save')
        ->assertSet('stale', true);

    expect($plan->fresh()->image_asset_id)->toBe($newer)
        ->and(MediaAsset::query()->count())->toBe(1);
});

it('keeps the saved text when the photo fails, says what was saved, and takes the photo on the next try', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Before']);
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

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'After')
        ->set('photo', UploadedFile::fake()->image('photo.jpg', 64, 64))
        ->call('save')
        ->assertHasErrors('photo')
        ->assertDispatched('rg-admin-toast', message: 'Changes saved, but the photo wasn’t.', tone: 'error')
        ->assertSet('lockVersion', 2)
        ->assertSet('postTitle', 'After');

    expect($plan->fresh())->title->toBe('After')->image_asset_id->toBeNull()
        ->and(MediaAsset::query()->exists())->toBeFalse()
        ->and(Storage::disk('public')->allFiles())->toBe([]);

    $failing->fail = false;
    $page->set('photo', UploadedFile::fake()->image('again.jpg', 64, 64))->call('save')->assertHasNoErrors()->assertSet('stale', false);

    expect($plan->fresh()->image_asset_id)->not->toBeNull()->and(StudioPlan::query()->count())->toBe(1);
});
