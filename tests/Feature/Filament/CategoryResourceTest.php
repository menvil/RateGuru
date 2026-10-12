<?php

use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Categories in Admin v2: one screen that lists them in their curated order
 * with their translation status, creates and edits each in a drawer,
 * activates and deactivates from the row menu with Undo, and deletes only
 * a category no post holds.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

/** @return list<string> the category names in the order the table draws them */
function categoryRowNames(Testable $page): array
{
    return array_values(array_map(fn (array $row): string => $row['name'], $page->viewData('rows')));
}

it('allows only admins to manage categories', function () {
    $this->get(CategoryResource::getUrl('index'))->assertOk();

    $this->actingAs(User::factory()->moderator()->create())
        ->get(CategoryResource::getUrl('index'))
        ->assertForbidden();
});

it('draws the screen in Admin v2, with its primary action in the top bar', function () {
    $html = $this->get(ManageCategories::getUrl())->assertOk()->getContent();

    expect($html)->toContain('rg-admin-screen')
        ->toContain('<h1 id="rg-admin-categories-title" class="rg-admin-page-header__title">Categories</h1>')
        ->toContain('x-on:click="$dispatch(\'create-category\')"')
        ->toContain('Create category')
        ->not->toContain('fi-header-heading');
});

it('lists every category in its curated order, with its posts', function () {
    $mango = Category::factory()->create(['name' => 'Mango', 'sort_order' => 1]);
    $zebra = Category::factory()->create(['name' => 'Zebra', 'sort_order' => 3, 'is_active' => false]);
    $apple = Category::factory()->create(['name' => 'Apple', 'sort_order' => 2]);
    Post::factory()->count(2)->create(['category_id' => $apple->id]);

    $page = Livewire::test(ManageCategories::class);

    expect(categoryRowNames($page))->toBe(['Mango', 'Apple', 'Zebra'])
        ->and($page->viewData('rows')[$apple->id]['posts'])->toBe(2)
        ->and($page->viewData('stats'))->toMatchArray(['active' => 2, 'inactive' => 1]);

    $page->assertSeeHtml('data-search="zebra '.$zebra->slug.'"')
        ->assertSeeHtml('Inactive');
});

it('creates a category in English, last in the order, with nothing translated yet', function () {
    Category::factory()->create(['sort_order' => 7]);

    $languages = count(offeredLocales()) - 1;

    Livewire::test(ManageCategories::class)
        ->call('create')
        ->assertSet('creating', true)
        ->assertSet('draftSortOrder', 8)
        ->set('draftName', 'Desserts')
        ->assertSet('draftSlug', 'desserts')
        ->set('draftActive', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('creating', false)
        ->assertDispatched('rg-admin-toast', message: "Desserts created. Translations are missing for {$languages} languages.");

    expect(Category::query()->where('slug', 'desserts')->firstOrFail())
        ->name->toBe('Desserts')->sort_order->toBe(8)->is_active->toBeTrue()->name_translations->toBeEmpty();
});

it('generates the slug from the name until one is typed', function () {
    Livewire::test(ManageCategories::class)
        ->call('create')
        ->set('draftName', 'Sweet Pastries')
        ->assertSet('draftSlug', 'sweet-pastries')
        ->assertSet('slugGenerated', true)
        ->set('draftSlug', 'pastries')
        ->assertSet('slugGenerated', false)
        ->set('draftName', 'Sweet Baked Pastries')
        ->assertSet('draftSlug', 'pastries');
});

it('opens the drawer on the category a search found nothing for', function () {
    Livewire::test(ManageCategories::class)
        ->call('create', '  Guinea   pigs ')
        ->assertSet('draftName', 'Guinea pigs')
        ->assertSet('draftSlug', 'guinea-pigs');
});

it('says what to do when a category cannot be saved', function (array $draft, string $field, string $message) {
    Category::factory()->create(['slug' => 'desserts']);

    $page = Livewire::test(ManageCategories::class)->call('create');

    foreach ($draft as $property => $value) {
        $page->set($property, $value);
    }

    $page->call('save')->assertHasErrors([$field]);

    expect($page->errors()->first($field))->toBe($message)
        ->and(Category::query()->count())->toBe(1);
})->with([
    'no name' => [['draftName' => '', 'draftSlug' => 'cakes'], 'draftName', 'Enter a name.'],
    'a slug in use' => [['draftName' => 'Desserts', 'draftSlug' => 'desserts'], 'draftSlug', 'This slug is already used by another category. Choose another one.'],
    'a slug that is not URL-safe' => [['draftName' => 'Cakes', 'draftSlug' => 'Sweet cakes'], 'draftSlug', 'Use lowercase letters and numbers, with single hyphens between words: small-pets.'],
    'a negative sort order' => [['draftName' => 'Cakes', 'draftSlug' => 'cakes', 'draftSortOrder' => -1], 'draftSortOrder', 'Enter a whole number from 0 to 999,999.'],
    'a sort order that is not a number' => [['draftName' => 'Cakes', 'draftSlug' => 'cakes', 'draftSortOrder' => '1.5'], 'draftSortOrder', 'Enter a whole number from 0 to 999,999.'],
]);

it('edits a category\'s name, order and visibility', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'slug' => 'dogs', 'sort_order' => 1, 'is_active' => true]);

    Livewire::test(ManageCategories::class)
        ->call('edit', $category->id)
        ->assertSet('editing', $category->id)
        ->assertSet('draftName', 'Dogs')
        ->set('draftName', 'Good dogs')
        ->set('draftSortOrder', '40')
        ->set('draftActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('editing', null)
        ->assertDispatched('rg-admin-toast', message: 'Good dogs saved');

    expect($category->fresh())->name->toBe('Good dogs')->slug->toBe('dogs')->sort_order->toBe(40)->is_active->toBeFalse();
});

it('changes a saved slug only once it is unlocked', function () {
    $category = Category::factory()->create(['slug' => 'dogs']);

    // Whatever arrives for a locked slug is not a change anybody asked for.
    Livewire::test(ManageCategories::class)
        ->call('edit', $category->id)
        ->set('draftSlug', 'puppies')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->slug)->toBe('dogs');

    Livewire::test(ManageCategories::class)
        ->call('edit', $category->id)
        ->call('unlockSlug')
        ->assertSet('slugUnlocked', true)
        ->assertSeeHtml('Changing the slug breaks existing links to this category.')
        ->set('draftSlug', 'puppies')
        ->assertSeeHtml('Feed links filtered by “dogs” stop working once you save.')
        ->call('save')
        ->assertHasNoErrors();

    expect($category->fresh()->slug)->toBe('puppies');
});

it('opens the drawer on the category the address names', function () {
    $category = Category::factory()->create(['name' => 'Dogs']);

    Livewire::withQueryParams(['edit' => (string) $category->id])
        ->test(ManageCategories::class)
        ->assertSet('editing', $category->id)
        ->assertSet('draftName', 'Dogs')
        ->assertSeeHtml('Edit category');
});

it('opens nothing for an address that names no category', function (string $edit) {
    Category::factory()->create();

    Livewire::withQueryParams(['edit' => $edit])
        ->test(ManageCategories::class)
        ->assertSet('editing', null)
        ->assertDontSeeHtml('Edit category');
})->with(['not a number' => 'dogs', 'a leading zero' => '01', 'none there' => '999999']);

it('keeps the drawer\'s state on the server', function (string $property, mixed $value) {
    Livewire::test(ManageCategories::class)->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([
        'the record it edits' => ['editing', 1],
        'whether it creates' => ['creating', true],
        'a locked slug' => ['slugUnlocked', true],
        'the record a confirmation is about' => ['deleting', 1],
    ]);

it('deactivates a category with Undo, and Undo activates it again', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'is_active' => true]);

    $page = Livewire::test(ManageCategories::class)
        ->call('deactivate', $category->id)
        ->assertDispatched('rg-admin-toast', message: 'Dogs deactivated', action: ['label' => 'Undo', 'event' => 'activate-category', 'params' => ['id' => $category->id, 'undo' => true]]);

    expect($category->fresh()->is_active)->toBeFalse();

    // What the toast's Undo dispatches.
    $page->dispatch('activate-category', id: $category->id, undo: true)
        ->assertDispatched('rg-admin-toast', message: 'Dogs is active again', action: null);

    expect($category->fresh()->is_active)->toBeTrue();
});

it('activates an inactive category with Undo', function () {
    $category = Category::factory()->create(['name' => 'Exotic', 'is_active' => false]);

    Livewire::test(ManageCategories::class)
        ->call('activate', $category->id)
        ->assertDispatched('rg-admin-toast', message: 'Exotic activated', action: ['label' => 'Undo', 'event' => 'deactivate-category', 'params' => ['id' => $category->id, 'undo' => true]]);

    expect($category->fresh()->is_active)->toBeTrue();
});

it('deletes an unused category after asking', function () {
    $category = Category::factory()->create(['name' => 'Exotic']);

    Livewire::test(ManageCategories::class)
        ->call('askToDelete', $category->id)
        ->assertSet('deleting', $category->id)
        ->assertSeeHtml('Delete Exotic?')
        ->assertSeeHtml('Delete category')
        ->call('delete', $category->id)
        ->assertSet('deleting', null)
        ->assertDispatched('rg-admin-toast', message: 'Exotic deleted');

    expect(Category::query()->find($category->id))->toBeNull();
});

it('offers to deactivate a category posts are filed under instead of deleting it', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'is_active' => true]);
    Post::factory()->count(3)->create(['category_id' => $category->id]);

    Livewire::test(ManageCategories::class)
        ->call('askToDelete', $category->id)
        ->assertSeeHtml('Dogs can’t be deleted')
        ->assertSeeHtml('3 posts are filed under Dogs.')
        ->assertSeeHtml('Deactivate Dogs')
        ->assertDontSeeHtml('Delete category')
        // A forged delete is refused by the action, and the dialog says why.
        ->call('delete', $category->id)
        ->assertSet('deleting', $category->id)
        ->call('deactivate', $category->id)
        ->assertSet('deleting', null);

    expect($category->fresh())->not->toBeNull()->is_active->toBeFalse();
});

it('counts a deleted post that is kept as one that holds its category', function () {
    $category = Category::factory()->create(['name' => 'Dogs', 'is_active' => false]);
    Post::factory()->create(['category_id' => $category->id])->delete();

    Livewire::test(ManageCategories::class)
        ->assertSeeHtml('Has posts · deactivate instead')
        ->call('askToDelete', $category->id)
        ->assertSeeHtml('Posts deleted by their authors and still kept are filed under Dogs.')
        ->assertSeeHtml('It is already inactive')
        ->assertDontSeeHtml('Deactivate Dogs');
});

it('shows each category\'s translations in the languages the project offers', function () {
    [$offered, $withheld] = twoTranslatedLocales();
    offerLocales([$offered]);
    $translated = Category::factory()->create(['name' => 'Dogs', 'name_translations' => [$offered => 'Hunde', $withheld => 'Perros'], 'is_active' => true]);
    $missing = Category::factory()->create(['name' => 'Cats', 'name_translations' => null, 'is_active' => true]);
    $hidden = Category::factory()->create(['name' => 'Exotic', 'name_translations' => null, 'is_active' => false]);

    $page = Livewire::test(ManageCategories::class);
    $rows = $page->viewData('rows');

    expect(array_column($rows[$translated->id]['chips'], 'code'))->toBe([$offered])
        ->and($rows[$translated->id]['missing'])->toBe(0)
        ->and($rows[$missing->id]['missing'])->toBe(1)
        // An inactive category is not translated while visitors cannot see it.
        ->and($rows[$hidden->id]['missing'])->toBe(0)
        ->and($rows[$hidden->id]['translate'])->toBeNull()
        ->and($page->viewData('stats')['missing'])->toBe(1);

    $page->assertSeeHtml('title="'.e(config("locales.supported.{$offered}.native")).': Hunde"')
        ->assertSeeHtml('Inactive · activate it to translate');
});

it('translates a category in Translation Center, in the first language it is missing from', function () {
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    $category = Category::factory()->create(['name_translations' => [$first => 'Hunde'], 'is_active' => true]);

    $page = Livewire::test(ManageCategories::class);

    expect($page->viewData('rows')[$category->id]['translate'])
        ->toBe(TranslationCenterPage::getUrl(['locale' => $second, 'section' => 'categories', 'unit' => "categories:{$category->id}:name"]))
        ->and($page->viewData('translationCenter'))->toBe(TranslationCenterPage::getUrl(['section' => 'categories']));
});

it('lists the other languages read-only in the drawer, with the English fallback', function () {
    [$offered] = twoTranslatedLocales();
    offerLocales([$offered]);
    $category = Category::factory()->create(['name' => 'Dogs', 'name_translations' => null]);

    Livewire::test(ManageCategories::class)
        ->call('edit', $category->id)
        ->assertSeeHtml('Other languages')
        ->assertSeeHtml('English fallback: Dogs')
        ->call('create')
        ->assertSeeHtml('Not translated yet')
        ->assertSeeHtml('Create the category first.');
});

it('refuses what names no category', function (string $method) {
    Livewire::test(ManageCategories::class)
        ->call($method, 999999)
        ->assertDispatched('rg-admin-toast', message: 'That category no longer exists.', tone: 'error');
})->with(['edit', 'activate', 'deactivate', 'askToDelete', 'delete']);

it('opens a category found by global search in its drawer', function () {
    $category = Category::factory()->create();

    expect(CategoryResource::getGlobalSearchResultUrl($category))->toBe(ManageCategories::getUrl(['edit' => $category->id]));
});
