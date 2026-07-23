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
        'key' => 'type',
        'label' => 'Type',
    ]);
    $second = RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Type B',
        'is_active' => true,
        'sort_order' => 20,
    ]);
    $first = RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Type A',
        'is_active' => true,
        'sort_order' => 10,
    ]);
    RatingOption::factory()->for($group, 'group')->create([
        'label' => 'Hidden Type',
        'is_active' => false,
        'sort_order' => 0,
    ]);

    $component = Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => 'type',
    ])
        ->assertSee('data-testid="rating-voting-type-'.$post->id.'"', false)
        ->assertSee('Type')
        ->assertDontSee('Hidden Type');

    expect(strpos($component->html(), $first->label))
        ->toBeLessThan(strpos($component->html(), $second->label));
});

it('marks the authenticated users selected rating option', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'type']);
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
            'groupKey' => 'type',
        ])
        ->assertSee('data-testid="rating-option-'.$post->id.'-'.$option->id.'"', false)
        ->assertSee('text-rg-accent', false);
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

it('renders binary rating distribution after the current user votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'type']);
    $first = RatingOption::factory()->for($group, 'group')->create(['label' => 'Type A']);
    $second = RatingOption::factory()->for($group, 'group')->create(['label' => 'Type B']);

    // 3 votes for first (incl. the current user), 1 vote for second → 75% (3) / 25% (1)
    RatingVote::factory()->count(2)->for($post)->for($group, 'group')->for($first, 'option')->create();
    RatingVote::factory()->for($post)->for($group, 'group')->for($first, 'option')->create(['user_id' => $user->id]);
    RatingVote::factory()->for($post)->for($group, 'group')->for($second, 'option')->create();

    Livewire::actingAs($user)
        ->test(RatingVoting::class, [
            'post' => $post,
            'groupKey' => 'type',
        ])
        ->assertSee('75% (3)')
        ->assertSee('25% (1)');
});

it('renders multi-option rating distribution after the current user votes', function () {
    $user = User::factory()->create();
    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create(['key' => 'attribute']);
    $a = RatingOption::factory()->for($group, 'group')->create(['label' => 'Alpha']);
    $b = RatingOption::factory()->for($group, 'group')->create(['label' => 'Beta']);
    $c = RatingOption::factory()->for($group, 'group')->create(['label' => 'Gamma']);

    RatingVote::factory()->for($post)->for($group, 'group')->for($a, 'option')->create(['user_id' => $user->id]);
    RatingVote::factory()->for($post)->for($group, 'group')->for($b, 'option')->create();

    // multi-option histogram → "percent · count votes" on the same line
    Livewire::actingAs($user)
        ->test(RatingVoting::class, [
            'post' => $post,
            'groupKey' => 'attribute',
        ])
        ->assertSee('50%')
        ->assertSee('1 vote');
});
