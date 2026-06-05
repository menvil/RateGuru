<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\RatingVote;
use App\Models\User;
use Livewire\Livewire;

it('renders active rating options for a group in sort order', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create([
        'key' => 'source',
        'label' => 'Source',
    ]);
    $second = RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Source B',
        'is_active' => true,
        'sort_order' => 20,
    ]);
    $first = RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Source A',
        'is_active' => true,
        'sort_order' => 10,
    ]);
    RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Hidden Source',
        'is_active' => false,
        'sort_order' => 0,
    ]);

    $component = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'source',
    ])
        ->assertSee('data-testid="rating-voting-source"', false)
        ->assertSee('Source')
        ->assertDontSee('Hidden Source');

    expect(strpos($component->html(), $first->label))
        ->toBeLessThan(strpos($component->html(), $second->label));
});

it('marks the authenticated users selected rating option', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $option = RatingOption::factory()->for($group, 'group')->create();

    RatingVote::factory()
        ->for($user)
        ->for($post)
        ->for($group, 'group')
        ->for($option, 'option')
        ->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, [
            'post' => $post,
            'groupKey' => 'source',
        ])
        ->assertSee('data-testid="rating-option-'.$option->id.'"', false)
        ->assertSee('aria-pressed="true"', false);
});

it('renders nothing for a missing or inactive rating group', function (string $groupKey) {
    $post = Post::factory()->published()->create();

    RatingGroup::factory()->create([
        'key' => 'inactive',
        'is_active' => false,
    ]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => $groupKey,
    ])->assertDontSee('data-testid="rating-voting-', false);
})->with(['missing', 'inactive']);

it('renders rating vote distribution for active options', function () {
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'source']);
    $first = RatingOption::factory()->for($group, 'group')->create(['label' => 'Source A']);
    $second = RatingOption::factory()->for($group, 'group')->create(['label' => 'Source B']);

    RatingVote::factory()->count(3)->for($post)->for($group, 'group')->for($first, 'option')->create();
    RatingVote::factory()->for($post)->for($group, 'group')->for($second, 'option')->create();

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'source',
    ])
        ->assertSee('3 votes · 75%')
        ->assertSee('1 vote · 25%');
});
