<?php

use App\Enums\PostStatus;
use App\Livewire\Feed\UploadPostForm;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('resets form when upload-modal-opened event is dispatched', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Old Dish')
        ->set('description', 'Old description')
        ->dispatch('upload-modal-opened')
        ->assertSet('title', '')
        ->assertSet('description', null)
        ->assertSet('submitError', null);
});

it('renders the upload form fields, loading state and image preview for an authenticated user', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertStatus(200)
        ->assertSee('Upload post')
        ->assertSee('Title')
        ->assertSee('name="title"', false)
        ->assertSee('Description')
        ->assertSee('name="description"', false)
        ->assertSee('Image')
        ->assertSee('type="file"', false)
        ->assertSee('name="image"', false)
        ->assertSee('Source URL')
        ->assertSee('name="source_url"', false)
        ->html();

    expect($html)
        ->toContain('wire:loading')
        ->toContain('Uploading')
        // Switching to the image url tab clears the selected file.
        ->toContain('wire:click="$set(\'image\', null)"')
        ->toContain('x-data')
        ->toContain('previewUrl')
        ->toContain('FileReader');
});

it('blocks guest users', function () {
    Livewire::test(UploadPostForm::class)
        ->assertForbidden();
});

it('accepts image upload property', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file);

    expect($component->get('image'))->not->toBeNull();
});

it('renders error message when submitError is set', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('submitError', 'Something went wrong while creating your post.')
        ->assertSee('Something went wrong');
});

it('tells a trusted author their post is published', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')
        ->set('image', $file)
        ->call('submit')
        ->assertDispatched('post-uploaded')
        ->assertDispatched('toast', message: __('ui.upload.success_published'));

    expect(Post::query()->sole()->status)->toBe(PostStatus::Published);
});

it('tells an author whose post waits for a moderator that it will appear after moderation', function () {
    Storage::fake('public');

    $user = User::factory()->create(['trust_level' => 0]);
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')
        ->set('image', $file)
        ->call('submit')
        ->assertDispatched('post-uploaded')
        ->assertDispatched('toast', message: __('ui.upload.success_pending'));

    expect(Post::query()->sole()->status)->toBe(PostStatus::Pending);
});

it('shows upload rate limit error without creating another post', function () {
    Storage::fake('public');
    config()->set('rate_limits.upload.max_attempts', 1);
    config()->set('rate_limits.upload.decay_seconds', 600);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'First Dish')
        ->set('image', UploadedFile::fake()->image('first.jpg'))
        ->call('submit')
        ->assertDispatched('post-uploaded');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Second Dish')
        ->set('image', UploadedFile::fake()->image('second.jpg'))
        ->call('submit')
        ->assertSet('submitError', 'You are uploading too quickly. Please try again later.')
        ->assertNotDispatched('post-uploaded');

    expect(Post::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('does not dispatch event on validation failure', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')
        ->call('submit')
        ->assertNotDispatched('post-uploaded');
});

it('shows validation error when title is missing', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['title' => 'required']);

    expect(Post::query()->count())->toBe(0);
});

it('renders validation error placeholders', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('submit')
        ->assertSee('field-error-title', false)
        ->assertSee('field-error-image', false);
});

it('shows validation error when image is missing', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')
        ->call('submit')
        ->assertHasErrors(['image' => 'required']);

    expect(Post::query()->count())->toBe(0);
});

it('rejects uploaded images above configured max size', function () {
    Storage::fake('public');
    config()->set('uploads.images.max_kilobytes', 5120);

    $user = User::factory()->create();

    $file = UploadedFile::fake()
        ->image('huge.jpg')
        ->size(6000);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Huge Image Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['image' => 'max'])
        ->assertNotDispatched('post-uploaded');

    expect(Post::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing('posts/'.$file->hashName());
});

it('allows uploaded images within configured max size', function () {
    Storage::fake('public');
    config()->set('uploads.images.max_kilobytes', 5120);

    $user = User::factory()->create();

    $file = UploadedFile::fake()
        ->image('ok.jpg')
        ->size(1000);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Allowed Image Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasNoErrors(['image'])
        ->assertDispatched('post-uploaded');

    expect(Post::query()->where('title', 'Allowed Image Test')->exists())->toBeTrue();
});

it('creates post on successful upload', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('dish.jpg');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Sample Entry')
        ->set('description', 'Fresh dinner')
        ->set('image', $file)
        ->call('submit');

    expect(Post::query()->where('user_id', $user->id)->where('title', 'Sample Entry')->exists())->toBeTrue();
});

it('renders selectable tags in an accessible combobox', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create(['name' => 'UniqueTagForTest']);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('Tags')
        ->assertSee('data-testid="upload-tag-search"', false)
        ->assertSee('data-testid="upload-tag-field"', false)
        ->assertSee('UniqueTagForTest')
        ->assertSee('data-testid="upload-tag-'.$tag->id.'"', false)
        ->assertSee('role="combobox"', false)
        ->assertSee('aria-controls="upload-tag-listbox"', false)
        ->assertSee('role="listbox"', false)
        ->assertSee('id="upload-tag-listbox"', false)
        ->assertSee('role="option"', false)
        ->assertSee('aria-selected="false"', false)
        ->assertSee('id="upload-tag-option-'.$tag->id.'"', false);
});

it('filters upload tags while typing and toggles selected tags', function () {
    $user = User::factory()->create();
    $matching = Tag::factory()->create(['name' => 'Carbonara']);
    Tag::factory()->create(['name' => 'Sushi']);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('tagSearch', 'carb')
        ->assertSee('Carbonara')
        ->call('toggleTag', $matching->id)
        ->assertSet('tagIds', [$matching->id]);
});

it('toggles a selected tag off', function () {
    $user = User::factory()->create();
    $tag = Tag::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->call('toggleTag', $tag->id)
        ->assertSet('tagIds', [$tag->id])
        ->call('toggleTag', $tag->id)
        ->assertSet('tagIds', []);
});

it('does not select more than ten tags', function () {
    $user = User::factory()->create();
    $tags = Tag::factory()->count(11)->create();

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class);

    $tags->each(fn (Tag $tag) => $component->call('toggleTag', $tag->id));

    expect($component->get('tagIds'))
        ->toHaveCount(10)
        ->not->toContain($tags->last()->id);
});

it('rejects more than ten submitted tag ids', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $tagIds = Tag::factory()->count(11)->create()->pluck('id')->all();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Post with too many tags')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('tagIds', $tagIds)
        ->call('submit')
        ->assertHasErrors(['tagIds' => 'max'])
        ->assertNotDispatched('post-uploaded');
});

it('rejects submitted tag ids that do not exist', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Post with invalid tag')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('tagIds', [PHP_INT_MAX])
        ->call('submit')
        ->assertHasErrors(['tagIds.0' => 'exists'])
        ->assertNotDispatched('post-uploaded');
});

it('does not query tags again during form interaction hydration', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'Italian']);

    DB::enableQueryLog();

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class);

    DB::flushQueryLog();

    $component->set('title', 'Hydrated title');

    $tagQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "tags"') || str_contains($query['query'], 'from `tags`'))
        ->count();

    expect($tagQueries)->toBe(0);
});

it('refreshes selectable tags when the upload modal opens again', function () {
    $user = User::factory()->create();
    Tag::factory()->create(['name' => 'InitialTagForTest']);

    $component = Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSee('InitialTagForTest')
        ->assertDontSee('NewTagAfterMount');

    $newTag = Tag::factory()->create(['name' => 'NewTagAfterMount']);

    $component
        ->dispatch('upload-modal-opened')
        ->assertSee('NewTagAfterMount')
        ->assertSee('data-testid="upload-tag-'.$newTag->id.'"', false);
});

it('rejects uploaded images with disallowed mime type', function () {
    Storage::fake('public');
    config()->set('uploads.images.mimes', ['jpg', 'jpeg', 'png', 'webp']);

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('file.gif');

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'GIF Upload Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['image'])
        ->assertNotDispatched('post-uploaded');

    expect(Post::query()->count())->toBe(0);
});

it('rejects uploaded images exceeding configured max dimensions', function () {
    Storage::fake('public');
    // The dimensions rule reads only the image header: one pixel past a
    // lowered limit is rejected the same way as one past the default 6000,
    // without having to encode an image that large.
    config()->set('uploads.images.max_width', 100);
    config()->set('uploads.images.max_height', 100);

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('oversized.jpg', 101, 101);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Huge Dimensions Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasErrors(['image' => 'dimensions'])
        ->assertNotDispatched('post-uploaded');

    expect(Post::query()->count())->toBe(0);
});

it('allows uploaded images within configured max dimensions', function () {
    Storage::fake('public');
    config()->set('uploads.images.max_width', 6000);
    config()->set('uploads.images.max_height', 6000);

    $user = User::factory()->create();
    $file = UploadedFile::fake()->image('ok.jpg', 800, 600);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Valid Dimensions Test')
        ->set('image', $file)
        ->call('submit')
        ->assertHasNoErrors(['image'])
        ->assertDispatched('post-uploaded');

    expect(Post::query()->where('title', 'Valid Dimensions Test')->exists())->toBeTrue();
});
