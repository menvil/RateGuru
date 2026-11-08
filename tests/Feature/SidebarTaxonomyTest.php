<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use Illuminate\Support\Facades\Cache;

/**
 * The sidebar's feed links as rendered: label => the feed query each leads to.
 *
 * @return array<string, array<string, mixed>>
 */
function sidebarFeedLinks(string $html): array
{
    $links = [];

    foreach (livewireDom($html)->query('//a[@href]') as $link) {
        $url = parse_url((string) $link->getAttribute('href'));

        if (($url['path'] ?? '/') !== '/' || ! isset($url['host'])) {
            continue;
        }

        parse_str($url['query'] ?? '', $query);
        $links[trim($link->textContent)] ??= $query;
    }

    return $links;
}

beforeEach(function () {
    Cache::forget('sidebar-nav-categories');
    Cache::forget('sidebar-nav-rating-group-options');
    Cache::forget('sidebar-nav-top-tag-slugs');
});

it('renders active standalone categories in configured order', function () {
    Category::factory()->create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 20]);
    Category::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 10]);
    Category::factory()->inactive()->create(['name' => 'Hidden', 'slug' => 'hidden', 'sort_order' => 5]);

    $group = RatingGroup::factory()->create(['sort_order' => 1]);
    RatingOption::factory()->create([
        'rating_group_id' => $group->id,
        'label' => 'Not a sidebar category',
        'sort_order' => 1,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeInOrder(['First', 'Second'])
        ->assertDontSee('Hidden')
        ->assertDontSee('Not a sidebar category')
        ->assertSee('category%5B0%5D=first', false);
});

it('marks the open sort, category and tag with the accent outline, as the current page', function (string $url, array $current) {
    Category::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 10]);
    Category::factory()->create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 20]);
    Post::factory()->published()->create()->tags()->attach(Tag::factory()->create(['slug' => 'pasta']));

    $dom = livewireDom($this->get($url)->assertOk()->getContent());

    // The sidebar is drawn twice, in the desktop column and the mobile drawer.
    $marked = collect($dom->query('//a[@aria-current="page"]'))
        ->map(fn (DOMElement $link): array => [trim($link->textContent), $link->getAttribute('class')])
        ->unique(fn (array $link): string => $link[0])
        ->values();

    expect($marked->pluck(0)->all())->toBe($current);

    $marked->each(fn (array $link) => expect($link[1])->toContain('border-rg-accentBorder')->toContain('bg-rg-accentSoft'));

    collect($dom->query('//nav[@aria-label="'.__('ui.a11y.primary_nav').'"]//a[not(@aria-current)]'))
        ->each(fn (DOMElement $link) => expect($link->getAttribute('class'))->not->toContain('bg-rg-accentSoft'));
})->with([
    // Home, and All among the categories: the default order, no category.
    'the whole feed' => ['/', ['Home', 'All']],
    'a sort' => ['/?sort=top', ['Top', 'All']],
    'a category' => ['/?category[]=first', ['Home', 'First']],
    'a tag' => ['/?tag=pasta', ['Home', 'All', '#pasta']],
    'all three' => ['/?sort=hot&category[]=second&tag=pasta', ['Hot', 'Second', '#pasta']],
]);

it('changes one part of the feed from each sidebar link and keeps the others', function () {
    Category::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 10]);
    Category::factory()->create(['name' => 'Second', 'slug' => 'second', 'sort_order' => 20]);
    Post::factory()->published()->create()->tags()->attach(Tag::factory()->create(['slug' => 'pasta']));

    $links = sidebarFeedLinks($this->get('/?sort=top&category[]=first&tag=pasta&page=3')->assertOk()->getContent());

    // A sort replaces the sort; Home is the default one.
    expect($links['Hot'])->toEqual(['sort' => 'hot', 'category' => ['first'], 'tag' => 'pasta'])
        ->and($links['Home'])->toEqual(['category' => ['first'], 'tag' => 'pasta'])
        // A category replaces the category; All removes it.
        ->and($links['Second'])->toEqual(['sort' => 'top', 'category' => ['second'], 'tag' => 'pasta'])
        ->and($links['All'])->toEqual(['sort' => 'top', 'tag' => 'pasta'])
        // A tag replaces the tag.
        ->and($links['#pasta'])->toEqual(['sort' => 'top', 'category' => ['first'], 'tag' => 'pasta']);
});

it('starts a fresh feed from the sidebar anywhere but the feed', function () {
    Category::factory()->create(['name' => 'First', 'slug' => 'first', 'sort_order' => 10]);
    $post = Post::factory()->published()->create();

    $html = $this->get(route('posts.show', $post).'?sort=top&tag=pasta')->assertOk()->getContent();
    $links = sidebarFeedLinks($html);

    expect($links['First'])->toEqual(['category' => ['first']])
        ->and($links['Top'])->toEqual(['sort' => 'top'])
        ->and($links['Home'])->toEqual([]);

    // Nothing is the current feed on a post's page.
    expect(livewireDom($html)->query('//nav[@aria-label="'.__('ui.a11y.primary_nav').'"]//a[@aria-current="page"]')->length)->toBe(0);
});

it('links top tags through the dedicated tag filter', function () {
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    $this->get('/')
        ->assertOk()
        ->assertSee(__('ui.nav.top_tags_heading'))
        ->assertSee('?tag=pasta', false)
        ->assertDontSee('?search=pasta', false);
});

it('leaves the top tags out, with no made-up ones, while no published post has a tag', function () {
    // Tags exist, but only on posts nobody can see, and the published post has none.
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    Post::factory()->published()->create();
    Post::factory()->pending()->create()->tags()->attach($tag);
    Post::factory()->hidden()->create()->tags()->attach($tag);

    $deleted = Post::factory()->published()->create();
    $deleted->tags()->attach($tag);
    $deleted->delete();

    $this->get('/')
        ->assertOk()
        ->assertDontSee(__('ui.nav.top_tags_heading'))
        ->assertDontSee('?tag=', false)
        ->assertDontSee('#sample-a');
});

it('leaves the top tags out on a site with no posts at all', function () {
    Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);

    $this->get('/')
        ->assertOk()
        ->assertDontSee(__('ui.nav.top_tags_heading'))
        ->assertDontSee('?tag=', false);
});

it('refreshes cached sidebar categories after admin taxonomy changes', function () {
    $category = Category::factory()->create(['name' => 'Old name', 'slug' => 'old-name']);

    $this->get('/')->assertSee('Old name');

    $category->update(['name' => 'New name', 'slug' => 'new-name']);

    $this->get('/')
        ->assertSee('New name')
        ->assertDontSee('Old name');
});
