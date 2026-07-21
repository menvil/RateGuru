<?php

use App\Livewire\Feed\UploadPostForm;
use App\Models\Post;
use App\Models\PostAuthorAnswer;
use App\Models\RatingGroup;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    seedFeedFilterGroups();

    $this->sourceGroup = RatingGroup::query()->where('key', 'source')->firstOrFail();
    $this->categoryGroup = RatingGroup::query()->where('key', 'category')->firstOrFail();
});

it('renders the category select with sidebar group options', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->assertSeeHtml('data-testid="upload-category-select"')
        ->assertSee('Category');
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
        ->assertSeeHtml('data-testid="upload-author-answer-source"')
        ->assertSeeHtml('data-testid="upload-author-answer-category"');
});

it('creates a post with category and author answers', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $categoryOption = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();
    $sourceAnswer = $this->sourceGroup->options()->where('key', 'source_b')->firstOrFail();
    $categoryAnswer = $this->categoryGroup->options()->where('key', 'category_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Answered dish')
        ->set('image', UploadedFile::fake()->image('dish.jpg'))
        ->set('categoryOptionId', (string) $categoryOption->id)
        ->set('knowsCorrectAnswer', true)
        ->set('authorAnswers', [
            (string) $this->sourceGroup->id => (string) $sourceAnswer->id,
            (string) $this->categoryGroup->id => (string) $categoryAnswer->id,
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Answered dish')->firstOrFail();

    expect($post->category_option_id)->toBe($categoryOption->id);
    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->pluck('rating_option_id')->sort()->values()->all())
        ->toBe(collect([$sourceAnswer->id, $categoryAnswer->id])->sort()->values()->all());
});

it('allows leaving author answer selects empty with the toggle on', function () {
    Storage::fake('public');

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Toggle only dish')
        ->set('image', UploadedFile::fake()->image('dish.jpg'))
        ->set('knowsCorrectAnswer', true)
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Toggle only dish')->firstOrFail();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});

it('ignores author answers when the toggle is off', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $sourceAnswer = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Toggle off dish')
        ->set('image', UploadedFile::fake()->image('dish.jpg'))
        ->set('authorAnswers', [
            (string) $this->sourceGroup->id => (string) $sourceAnswer->id,
        ])
        ->call('submit')
        ->assertHasNoErrors();

    $post = Post::query()->where('title', 'Toggle off dish')->firstOrFail();

    expect(PostAuthorAnswer::query()->where('post_id', $post->id)->count())->toBe(0);
});

it('rejects a category value that is not a sidebar group option', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $secondGroupOption = $this->categoryGroup->options()->where('key', 'category_b')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('title', 'Invalid category dish')
        ->set('image', UploadedFile::fake()->image('dish.jpg'))
        ->set('categoryOptionId', (string) $secondGroupOption->id)
        ->call('submit')
        ->assertHasErrors(['categoryOptionId']);

    expect(Post::query()->where('title', 'Invalid category dish')->exists())->toBeFalse();
});

it('resets category and author answers when the upload modal reopens', function () {
    $user = User::factory()->create();
    $categoryOption = $this->sourceGroup->options()->where('key', 'source_a')->firstOrFail();

    Livewire::actingAs($user)
        ->test(UploadPostForm::class)
        ->set('categoryOptionId', (string) $categoryOption->id)
        ->set('knowsCorrectAnswer', true)
        ->set('authorAnswers', [(string) $this->sourceGroup->id => (string) $categoryOption->id])
        ->dispatch('upload-modal-opened')
        ->assertSet('categoryOptionId', '')
        ->assertSet('knowsCorrectAnswer', false)
        ->assertSet('authorAnswers', []);
});
