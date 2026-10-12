<?php

use App\Filament\Pages\TranslationCenterPage;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

/**
 * Tags in Admin v2: one screen that lists them, the most used first, with
 * their translation status. Moderators curate them with administrators; only
 * an administrator deletes one or translates it, and Merge into… is shown
 * unavailable until merging tags is designed.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
});

it('allows admin to access tag resource index', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access tag resource index', function () {
    $this->actingAs(User::factory()->moderator()->create())
        ->get(TagResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access tag resource index', function () {
    $this->actingAs(User::factory()->create())
        ->get(TagResource::getUrl('index'))
        ->assertForbidden();
});

it('renders in the Content section of the admin navigation', function () {
    expect(TagResource::getNavigationGroup())->toBe(AdminNavigationGroup::CONTENT)
        ->and(TagResource::getNavigationSort())->toBe(20);
});

it('draws the screen in Admin v2, with its primary action in the top bar', function () {
    $html = $this->actingAs(User::factory()->moderator()->create())
        ->get(ManageTags::getUrl())
        ->assertOk()
        ->getContent();

    expect($html)->toContain('rg-admin-screen')
        ->toContain('<h1 id="rg-admin-tags-title" class="rg-admin-page-header__title">Tags</h1>')
        ->toContain('x-on:click="$dispatch(\'create-tag\')"')
        ->not->toContain('fi-header-heading');
});

it('lists every tag, the most used first, with its posts', function () {
    $this->actingAs(User::factory()->admin()->create());
    $pasta = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    $apple = Tag::factory()->create(['name' => 'Apple', 'slug' => 'apple']);
    $zebra = Tag::factory()->create(['name' => 'Zebra', 'slug' => 'zebra']);

    foreach (Post::factory()->count(2)->published()->create() as $post) {
        $post->tags()->attach($pasta);
    }

    $page = Livewire::test(ManageTags::class);

    expect(array_column(array_values($page->viewData('rows')), 'name'))->toBe(['Pasta', 'Apple', 'Zebra'])
        ->and($page->viewData('rows')[$pasta->id]['posts'])->toBe(2)
        ->and($page->viewData('stats'))->toMatchArray(['tags' => 3, 'unused' => 2]);

    $page->assertSeeHtml('data-search="pasta pasta"')->assertSeeHtml('Sorted by number of posts.');
});

it('shows Merge into… unavailable, with the reason', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create();

    $item = livewireFragment(Livewire::test(ManageTags::class), "//*[@id='rg-admin-tag-{$tag->id}-actions-menu']//*[@role='menuitem'][1]");

    expect($item)->toContain('aria-disabled="true"')
        ->toContain('Merge into…')
        ->toContain('Not available yet')
        ->not->toContain('wire:click');
});

it('lets an administrator delete an unused tag after asking', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create(['name' => 'halloween 2025', 'slug' => 'halloween-2025']);

    Livewire::test(ManageTags::class)
        ->call('askToDelete', $tag->id)
        ->assertSeeHtml('Delete #halloween 2025?')
        ->assertSeeHtml('Delete tag')
        ->call('delete', $tag->id)
        ->assertDispatched('rg-admin-toast', message: '#halloween 2025 deleted');

    expect(Tag::query()->find($tag->id))->toBeNull();
});

it('explains why a tag posts carry cannot be deleted, with nothing to confirm', function () {
    $this->actingAs(User::factory()->admin()->create());
    $tag = Tag::factory()->create(['name' => 'rescue']);
    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    Livewire::test(ManageTags::class)
        ->assertSeeHtml('In use · remove it from its posts first')
        ->call('askToDelete', $tag->id)
        ->assertSeeHtml('#rescue is still in use')
        ->assertSeeHtml('It is attached to 1 post. Remove it from that post before deleting it.')
        ->assertDontSeeHtml('Delete tag')
        // A forged delete is refused by the action, and the dialog stays.
        ->call('delete', $tag->id)
        ->assertSet('deleting', $tag->id);

    // Never detached on the way: the post keeps its tag.
    expect($tag->fresh())->not->toBeNull()
        ->and($post->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

it('lets a moderator curate tags but not delete them', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $tag = Tag::factory()->create();

    $page = Livewire::test(ManageTags::class);
    $delete = livewireFragment($page, "//*[@id='rg-admin-tag-{$tag->id}-actions-menu']//*[@role='menuitem'][last()]");

    expect($delete)->toContain('aria-disabled="true"')->toContain('Only administrators delete tags');

    $page->call('askToDelete', $tag->id)
        ->assertSet('deleting', null)
        ->assertDispatched('rg-admin-toast', message: 'Only administrators delete tags.', tone: 'error')
        ->call('delete', $tag->id)
        ->assertDispatched('rg-admin-toast', message: 'Only administrators delete tags.', tone: 'error');

    expect($tag->fresh())->not->toBeNull();
});

it('translates a tag in Translation Center, which only an administrator opens', function () {
    [$offered] = twoTranslatedLocales();
    offerLocales([$offered]);
    $tag = Tag::factory()->create(['name_translations' => null]);

    $this->actingAs(User::factory()->admin()->create());
    $admin = Livewire::test(ManageTags::class);

    expect($admin->viewData('rows')[$tag->id])
        ->translate->toBe(TranslationCenterPage::getUrl(['locale' => $offered, 'section' => 'tags', 'unit' => "tags:{$tag->id}:name"]))
        ->missing->toBe(1)
        ->and($admin->viewData('stats')['missing'])->toBe(1);

    $this->actingAs(User::factory()->moderator()->create());
    $moderator = Livewire::test(ManageTags::class);

    expect($moderator->viewData('rows')[$tag->id]['translate'])->toBeNull()
        ->and($moderator->viewData('translationCenter'))->toBeNull();

    $moderator->assertSeeHtml('Only administrators translate');
});

it('opens a tag found by global search in its drawer', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $tag = Tag::factory()->create();

    expect(TagResource::getGlobalSearchResultUrl($tag))->toBe(ManageTags::getUrl(['edit' => $tag->id]));
});
