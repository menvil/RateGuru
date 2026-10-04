<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\Category;
use App\Models\Post;
use App\Models\PostAuthorAnswer;
use App\Models\RatingGroup;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->typeGroup = RatingGroup::query()->where('key', 'type')->firstOrFail();
    $this->attributeGroup = RatingGroup::query()->where('key', 'attribute')->firstOrFail();
});

it('renders the category select with active standalone categories', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Desserts']);
    Category::factory()->inactive()->create(['name' => 'Hidden category']);

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSeeHtml('data-testid="upload-category-select"')
        ->assertSee('Desserts')
        ->assertDontSee('Hidden category');
});

it('renders the author section with an Alpine-toggled answers block', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)->test(UploadPostForm::class)->html();

    // The answers block is always in the DOM; visibility is client-side
    // (x-show bound to the entangled knowsCorrectAnswer flag), so toggling
    // the checkbox needs no server round trip.
    expect($html)
        ->toContain('data-testid="upload-author-section"')
        ->toContain('data-testid="upload-knows-answer-toggle"')
        ->toContain('data-testid="upload-author-answers"')
        ->toContain('x-show="knowsAnswer"')
        ->toContain("entangle('knowsCorrectAnswer')");
});

it('renders one answer select per rating group', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSeeHtml('data-testid="upload-author-answer-type"')
        ->assertSeeHtml('data-testid="upload-author-answer-attribute"');
});

it('creates a post with category and author answers', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $category = Category::factory()->create();
    $typeAnswer = $this->typeGroup->options()->where('key', 'type_b')->firstOrFail();
    $attributeAnswer = $this->attributeGroup->options()->where('key', 'attribute_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Answered post')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('categoryId', (string) $category->id)
        ->set('knowsCorrectAnswer', true)
        ->set('authorAnswers', [
            (string) $this->typeGroup->id => (string) $typeAnswer->id,
            (string) $this->attributeGroup->id => (string) $attributeAnswer->id,
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Answered post')->firstOrFail();

    expect($post->category_id)->toBe($category->id);
    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->pluck('rating_option_id')->sort()->values()->all())
        ->toBe(collect([$typeAnswer->id, $attributeAnswer->id])->sort()->values()->all());
});

it('allows leaving author answer selects empty with the toggle on', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Toggle only post')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('knowsCorrectAnswer', true)
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Toggle only post')->firstOrFail();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});

it('ignores author answers when the toggle is off', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $typeAnswer = $this->typeGroup->options()->where('key', 'type_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Toggle off post')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('authorAnswers', [
            (string) $this->typeGroup->id => (string) $typeAnswer->id,
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Toggle off post')->firstOrFail();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});

it('rejects an inactive category value', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $category = Category::factory()->inactive()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Invalid category post')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('categoryId', (string) $category->id)
        ->call('submit')
        ->assertHasErrors(['categoryId']);

    expect(Post::query()->where('title', 'Invalid category post')->exists())->toBeFalse();
});

it('rejects a non-numeric category before checking category existence', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Malformed category post')
        ->set('image', UploadedFile::fake()->image('post.jpg'))
        ->set('categoryId', 'not-a-number')
        ->call('submit')
        ->assertHasErrors(['categoryId' => 'integer']);

    expect(Post::query()->where('title', 'Malformed category post')->exists())->toBeFalse();
});

it('resets category and author answers when the upload modal reopens', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    $typeOption = $this->typeGroup->options()->where('key', 'type_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('categoryId', (string) $category->id)
        ->set('knowsCorrectAnswer', true)
        ->set('authorAnswers', [(string) $this->typeGroup->id => (string) $typeOption->id])
        ->dispatch('upload-modal-opened')
        ->assertSet('categoryId', '')
        ->assertSet('knowsCorrectAnswer', false)
        ->assertSet('authorAnswers', []);
});
