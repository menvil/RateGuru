<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\Tag;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget('sidebar-nav-categories');
    Cache::forget('sidebar-nav-rating-group-options');
    Cache::forget('sidebar-nav-top-tags');
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

it('links top tags through the dedicated tag filter', function () {
    $tag = Tag::factory()->create(['name' => 'Pasta', 'slug' => 'pasta']);
    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    $this->get('/')
        ->assertOk()
        ->assertSee('?tag=pasta', false)
        ->assertDontSee('?search=pasta', false);
});

it('refreshes cached sidebar categories after admin taxonomy changes', function () {
    $category = Category::factory()->create(['name' => 'Old name', 'slug' => 'old-name']);

    $this->get('/')->assertSee('Old name');

    $category->update(['name' => 'New name', 'slug' => 'new-name']);

    $this->get('/')
        ->assertSee('New name')
        ->assertDontSee('Old name');
});
