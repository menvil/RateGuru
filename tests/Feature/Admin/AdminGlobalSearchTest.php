<?php

use App\Filament\Resources\Posts\PostResource;
use App\Livewire\Admin\GlobalSearch;
use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch as FilamentGlobalSearch;
use Livewire\Livewire;

/**
 * The sidebar search: Filament's global search in Admin v2 markup. What is
 * searched and what each user may find stays Filament's; these tests prove
 * the field and the results list draw it faithfully.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('is Filament\'s global search, not a search of its own', function () {
    expect(is_subclass_of(GlobalSearch::class, FilamentGlobalSearch::class))->toBeTrue();
});

it('draws the field the way the reference does, with no results before a query', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(GlobalSearch::class)
        ->assertSeeHtml('<label class="rg-admin-search" for="rg-admin-search">')
        ->assertSeeHtml('placeholder="Search posts, users"')
        ->assertSeeHtml('aria-label="Search the admin"')
        ->assertSeeHtml('wire:model.live.debounce.500ms="search"')
        ->assertSeeHtml('class="rg-admin-kbd"')
        ->assertDontSeeHtml('id="rg-admin-search-results"')
        // The live region is there before the first query, so its first announcement is heard.
        ->assertSeeHtml('<p class="rg-admin-sr-only" role="status" aria-live="polite">');
});

it('lists matching records with their kind and a link to each', function () {
    $this->actingAs(User::factory()->admin()->create());
    $post = Post::factory()->published()->create(['title' => 'Searchable sunset photo']);

    Livewire::test(GlobalSearch::class)
        ->set('search', 'Searchable sunset')
        ->assertSeeHtml('id="rg-admin-search-results"')
        ->assertSeeHtml('<span class="rg-admin-search-result__title">Searchable sunset photo</span>')
        ->assertSeeHtml('<span class="rg-admin-search-result__meta">Post</span>')
        ->assertSeeHtml('rg-admin-search-result rg-admin-search-result--first')
        ->assertSeeHtml('<p class="rg-admin-sr-only" role="status" aria-live="polite">')
        ->assertSeeHtmlInOrder(['role="status"', '1 result', '</p>'])
        ->assertSeeHtml('href="'.e(PostResource::getGlobalSearchResultUrl($post)).'"');
});

it('says so when nothing matches', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(GlobalSearch::class)
        ->set('search', 'zzz-nothing-like-this')
        ->assertSee('Nothing in the admin matches “zzz-nothing-like-this”.')
        ->assertSeeHtmlInOrder(['role="status"', 'Nothing in the admin matches', '</p>']);
});

it('finds only what Filament lets the user search', function () {
    Category::factory()->create(['name' => 'Searchable category']);

    $this->actingAs(User::factory()->admin()->create());
    $result = '<span class="rg-admin-search-result__title">Searchable category</span>';

    Livewire::test(GlobalSearch::class)->set('search', 'Searchable category')->assertSeeHtml($result);

    // Categories are admin-only; a moderator's search never returns them.
    $this->actingAs(User::factory()->moderator()->create());
    Livewire::test(GlobalSearch::class)
        ->set('search', 'Searchable category')
        ->assertDontSeeHtml($result)
        ->assertSee('Nothing in the admin matches');
});
