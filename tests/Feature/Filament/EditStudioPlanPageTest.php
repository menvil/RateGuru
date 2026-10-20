<?php

use App\Actions\Studio\UpdateStudioPlanAction;
use App\Data\Studio\StudioPlanData;
use App\Enums\StudioPlanPublicationMode;
use App\Enums\StudioPlanStatus;
use App\Enums\UserStatus;
use App\Filament\Resources\StudioPlans\Pages\EditStudioPlan;
use App\Filament\Resources\StudioPlans\StudioPlanResource;
use App\Livewire\Admin\StudioPlanTextSuggestionsCard;
use App\Models\Category;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\StudioPlan;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * One publication's editor: drawn from what is saved, four steps that move
 * nothing and lose nothing, each field saved through the plan's actions,
 * Discard, and a save after somebody else's refused — keeping what was typed
 * until Reload. Nothing is published, scheduled or sent anywhere.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/** A draft with everything an editor sets. */
function studioPlanWithEverything(array $attributes = []): StudioPlan
{
    $option = RatingOption::factory()->create();

    return StudioPlan::factory()->authoredBy(User::factory()->persona()->create(['username' => 'night_owl']))->create([
        'title' => 'A quiet harbour',
        'description' => 'Boats at dawn.',
        'source_url' => 'https://example.com/harbour',
        'category_id' => Category::factory()->create()->id,
        'tag_ids' => Tag::factory()->count(2)->create()->modelKeys(),
        'author_answer_option_ids' => [$option->id],
        'publication_mode' => StudioPlanPublicationMode::AtDate,
        'publish_at' => CarbonImmutable::parse('2019-05-06 07:08:00'),
        ...$attributes,
    ]);
}

it('draws the editor with Save draft in the top bar and every field as saved', function () {
    $plan = studioPlanWithEverything();
    $option = RatingOption::query()->findOrFail($plan->author_answer_option_ids[0]);

    expect($this->get(StudioPlanResource::getUrl('edit', ['record' => $plan]))->assertOk()->getContent())
        ->toContain('A quiet harbour')
        ->toContain('Save draft')
        ->toContain('Step 1 of 4 · Image')
        ->toContain('Upload &amp; analysis')
        ->toContain('Drop a photo here');

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertSet('postTitle', 'A quiet harbour')
        ->assertSet('postDescription', 'Boats at dawn.')
        ->assertSet('sourceUrl', 'https://example.com/harbour')
        ->assertSet('authorUserId', (string) $plan->author_user_id)
        ->assertSet('categoryId', (string) $plan->category_id)
        ->assertSet('tagIds', $plan->tag_ids)
        ->assertSet('authorAnswers', [$option->rating_group_id => (string) $option->id])
        ->assertSet('publicationMode', 'at_date')
        ->assertSet('publishAt', '2019-05-06T07:08')
        ->assertSet('lockVersion', 1)
        ->assertSet('step', 'image');
});

it('moves between the four steps without saving or losing anything typed', function () {
    $plan = studioPlanWithEverything();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'Typed, not saved')
        ->call('nextStep')->assertSet('step', 'post')->assertSee('Final title')->assertSeeLivewire(StudioPlanTextSuggestionsCard::class)
        ->call('nextStep')->assertSet('step', 'discussion')->assertSeeHtml('wire:name="admin.studio-plan-discussion-editor"')->assertDontSee('Generated conversations')
        ->call('nextStep')->assertSet('step', 'publication')->assertSee('This is a draft. Saving it does not publish or schedule anything.')->assertDontSee('Review and confirm')
        ->call('nextStep')->assertSet('step', 'publication')
        ->call('goToStep', 'image')->assertSet('step', 'image')
        ->call('goToStep', 'somewhere')->assertSet('step', 'image')
        ->call('previousStep')->assertSet('step', 'image')
        ->assertSet('postTitle', 'Typed, not saved');

    expect($plan->fresh())->title->toBe('A quiet harbour')->lock_version->toBe(1);

    // The publication card shows the draft as it stands, saved or not.
    $page->call('goToStep', 'publication')->assertSee('Typed, not saved')->assertSee('06 May 2019, 07:08');
});

it('saves the texts and settings, and draws them as saved', function () {
    $plan = studioPlanWithEverything();
    $persona = User::factory()->persona()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();
    $group = RatingGroup::factory()->create();
    $answer = RatingOption::factory()->create(['rating_group_id' => $group->id]);

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'A new title')
        ->set('postDescription', 'A new description.')
        ->set('sourceUrl', '')
        ->call('chooseAuthor', $persona->id)
        ->set('categoryId', (string) $category->id)
        ->call('removeTag', $plan->tag_ids[0])
        ->call('addTag', $tag->id)
        ->set("authorAnswers.{$group->id}", (string) $answer->id)
        ->set('publicationMode', 'at_date')
        ->set('publishAt', '2031-12-24T18:30')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'Draft saved', tone: 'success')
        ->assertSet('lockVersion', 2);

    $saved = $plan->fresh();

    expect($saved)
        ->title->toBe('A new title')
        ->description->toBe('A new description.')
        ->source_url->toBeNull()
        ->author_user_id->toBe($persona->id)
        ->category_id->toBe($category->id)
        ->tag_ids->toBe(collect([$plan->tag_ids[1], $tag->id])->sort()->values()->all())
        ->author_answer_option_ids->toContain($answer->id)
        ->and($saved->publish_at->toDateTimeString())->toBe('2031-12-24 18:30:00');

    // Nothing is left unsaved: what the browser compares is what was saved.
    expect($page->get('saved'))->toMatchArray([
        'title' => 'A new title',
        'publishAt' => '2031-12-24T18:30',
    ]);

    // Drawn again from the database, the editor shows what was saved.
    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertSet('postTitle', 'A new title')
        ->assertSet('publishAt', '2031-12-24T18:30')
        ->assertSet('authorUserId', (string) $persona->id);
});

it('keeps a date in the past and one in the future, and the moment exactly', function (string $input, string $stored) {
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('publicationMode', 'at_date')
        ->set('publishAt', $input)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh()->publish_at->toDateTimeString())->toBe($stored);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->assertSet('publishAt', $input);
})->with([
    'the past' => ['2015-03-01T09:15', '2015-03-01 09:15:00'],
    'the future' => ['2034-07-08T21:45', '2034-07-08 21:45:00'],
]);

it('keeps no date for a plan published once approved', function () {
    $plan = studioPlanWithEverything();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('publicationMode', 'now')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh())->publication_mode->toBe(StudioPlanPublicationMode::Now)->publish_at->toBeNull();
});

it('says a chosen date is missing without refusing the draft', function () {
    $plan = StudioPlan::factory()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('goToStep', 'publication')
        ->set('publicationMode', 'at_date')
        ->assertSee('Publication date not selected')
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh())->publication_mode->toBe(StudioPlanPublicationMode::AtDate)->publish_at->toBeNull();
});

it('changes the author without touching the photo', function () {
    Queue::fake();
    $plan = StudioPlan::factory()->withImage()->authoredBy(User::factory()->persona()->create())->create(['created_by_user_id' => $this->admin->id]);
    $asset = $plan->imageAsset;
    $other = User::factory()->persona()->create();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('chooseAuthor', $other->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($plan->fresh())->author_user_id->toBe($other->id)->image_asset_id->toBe($asset->id)
        ->and($asset->fresh())->owner_user_id->toBe($this->admin->id)->path->toBe($asset->path)->trashed()->toBeFalse()
        ->and(MediaAsset::withTrashed()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('offers living personas only as the author, found on the server', function () {
    $owl = User::factory()->persona()->create(['username' => 'night_owl']);
    $limited = User::factory()->persona()->create(['username' => 'grumpy_max', 'status' => UserStatus::Limited]);
    User::factory()->persona()->tombstoned()->create(['username' => 'gone_persona']);
    User::factory()->create(['username' => 'plain_member']);
    User::factory()->moderator()->create(['username' => 'a_moderator']);

    $page = Livewire::test(EditStudioPlan::class, ['record' => StudioPlan::factory()->create()->id])->call('goToStep', 'post');

    expect(collect($page->viewData('authorChoices'))->pluck('username')->all())->toEqualCanonicalizing(['night_owl', 'grumpy_max']);

    $page->set('authorSearch', 'grumpy');
    expect(collect($page->viewData('authorChoices'))->pluck('username')->all())->toBe(['grumpy_max']);

    $page->call('chooseAuthor', $limited->id)
        ->assertSet('authorUserId', (string) $limited->id)
        ->assertSee('This persona cannot publish while restricted.');

    foreach (['plain_member', 'a_moderator', 'gone_persona'] as $username) {
        $page->call('chooseAuthor', User::query()->where('username', $username)->value('id'))->assertHasErrors('authorUserId');
    }

    $page->assertSet('authorUserId', (string) $limited->id);
    expect($owl)->not->toBeNull();
});

it('tells about an author deleted since, names nobody, and saves once the author is changed or cleared', function () {
    $persona = User::factory()->persona()->create(['username' => 'gone_owl', 'name' => 'Gone Owl', 'display_name' => 'Gone Owl']);
    $plan = StudioPlan::factory()->authoredBy($persona)->create(['title' => 'Kept']);
    $persona->forceFill(['status' => UserStatus::Deleted, 'anonymized_at' => now()])->save();

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->call('goToStep', 'post')
        ->assertSee('The author’s account was deleted.')
        ->assertSee('Deleted user')
        ->assertDontSee('gone_owl')
        ->set('postTitle', 'Retitled')
        ->call('save')
        ->assertHasErrors('authorUserId');

    expect($plan->fresh()->title)->toBe('Kept');

    $page->call('clearAuthor')->call('save')->assertHasNoErrors();

    expect($plan->fresh())->title->toBe('Retitled')->author_user_id->toBeNull();
});

it('keeps an inactive category the draft has, saying so, and replaces nothing unseen', function () {
    $category = Category::factory()->inactive()->create(['name' => 'Retired']);
    $plan = StudioPlan::factory()->create(['category_id' => $category->id]);

    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->call('goToStep', 'post');

    expect(collect($page->viewData('categories'))->pluck('label')->all())->toContain('Retired · inactive');

    $page->set('postTitle', 'Still retired')->call('save')->assertHasNoErrors();

    expect($plan->fresh()->category_id)->toBe($category->id);
});

it('puts the saved values back on Discard', function () {
    $plan = studioPlanWithEverything();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'Changed')
        ->set('publicationMode', 'now')
        ->dispatch('discard-studio-plan')
        ->assertSet('postTitle', 'A quiet harbour')
        ->assertSet('publicationMode', 'at_date')
        ->assertHasNoErrors();

    expect($plan->fresh()->lock_version)->toBe(1);
});

it('puts back on Discard what is saved now, with its version, so the next save is no conflict', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Original']);
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('postTitle', 'Mine');

    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Theirs'), 1);
    $page->call('save')->assertSet('stale', true);

    $page->dispatch('discard-studio-plan')
        ->assertSet('postTitle', 'Theirs')
        ->assertSet('lockVersion', 2)
        ->assertSet('stale', false);

    expect($page->get('saved')['title'])->toBe('Theirs');

    $page->set('postTitle', 'Mine, after all')->call('save')->assertSet('stale', false)->assertHasNoErrors();

    expect($plan->fresh())->title->toBe('Mine, after all')->lock_version->toBe(3);
});

it('leaves an empty draft that can no longer be saved when Discard finds the plan deleted', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Original']);
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('postTitle', 'Mine');

    StudioPlan::query()->whereKey($plan->id)->delete();

    $page->dispatch('discard-studio-plan')
        ->assertSet('gone', true)
        ->assertSet('postTitle', '');
});

it('refuses a save drawn before somebody else\'s, keeps what was typed, and Reload draws theirs', function () {
    $plan = StudioPlan::factory()->create(['title' => 'Original']);
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('postTitle', 'Mine');

    app(UpdateStudioPlanAction::class)->handle(User::factory()->admin()->create(), $plan, new StudioPlanData(title: 'Theirs'), 1);

    $page->call('save')
        ->assertSet('stale', true)
        ->assertSet('postTitle', 'Mine')
        ->assertDispatched('rg-admin-toast', message: 'Someone saved this draft after you opened it, so nothing was saved.', tone: 'error')
        ->assertSee('Someone saved this draft after you opened it.');

    expect($plan->fresh())->title->toBe('Theirs')->lock_version->toBe(2);

    $page->call('reload')
        ->assertSet('stale', false)
        ->assertSet('postTitle', 'Theirs')
        ->assertSet('lockVersion', 2);
});

it('saves nothing to a draft deleted since the editor was drawn, and says so', function () {
    $plan = StudioPlan::factory()->create();
    $page = Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set('postTitle', 'Too late');

    StudioPlan::query()->whereKey($plan->id)->delete();

    $page->call('save')
        ->assertSet('gone', true)
        ->assertDispatched('rg-admin-toast', message: 'This draft was deleted, so nothing was saved.', tone: 'error')
        ->assertSee('This draft was deleted, so nothing here can be saved any more.');

    expect(StudioPlan::query()->exists())->toBeFalse();
});

it('opens a plan past its draft closed, and saves nothing to it', function () {
    $plan = StudioPlan::factory()->status(StudioPlanStatus::Approved)->create(['title' => 'Approved']);

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->assertSet('closed', true)
        ->assertSee('This plan is no longer a draft, so it can’t be edited here.')
        ->assertDontSee('Delete draft')
        ->set('postTitle', 'Changed')
        ->call('save');

    expect($plan->fresh()->title)->toBe('Approved');
});

it('takes no status, version or identity from a request', function (string $property, mixed $value) {
    $plan = StudioPlan::factory()->create();

    expect(fn () => Livewire::test(EditStudioPlan::class, ['record' => $plan->id])->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);

    expect($plan->fresh())->lock_version->toBe(1)->status->toBe(StudioPlanStatus::Draft);
})->with([
    'the version' => ['lockVersion', 99],
    'the plan' => ['planId', 2_000_000_000],
    'what it was drawn from' => ['fingerprint', 'forged'],
    'what is saved' => ['saved', []],
    'a closed plan' => ['closed', false],
    'the step on screen' => ['step', 'nowhere'],
]);

it('publishes, queues and asks AI for nothing', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $plan = studioPlanWithEverything();

    Livewire::test(EditStudioPlan::class, ['record' => $plan->id])
        ->set('postTitle', 'Still a draft')
        ->set('publicationMode', 'now')
        ->call('save')
        ->assertHasNoErrors();

    expect(Post::query()->withTrashed()->exists())->toBeFalse()
        ->and($plan->fresh()->status)->toBe(StudioPlanStatus::Draft);
    Queue::assertNothingPushed();
});
