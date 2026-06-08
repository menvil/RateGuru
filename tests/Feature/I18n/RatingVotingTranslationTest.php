<?php

use App\Livewire\Voting\RatingVoting;
use App\Models\Post;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use Livewire\Livewire;

it('renders translated rating group and option labels', function () {
    app()->setLocale('ru');

    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create([
        'key' => 'source_'.uniqid(),
        'label' => 'Source',
        'label_translations' => ['ru' => 'Источник'],
        'is_active' => true,
    ]);

    RatingOption::factory()->create([
        'rating_group_id' => $group->id,
        'label' => 'Option A',
        'label_translations' => ['ru' => 'Вариант А'],
        'is_active' => true,
    ]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => $group->key,
    ])
        ->assertSee('Источник')
        ->assertSee('Вариант А');
});

it('falls back to base rating label when translation is missing', function () {
    app()->setLocale('bg');

    $post = Post::factory()->published()->create();
    $group = RatingGroup::factory()->create([
        'key' => 'source_'.uniqid(),
        'label' => 'Source',
        'label_translations' => null,
        'is_active' => true,
    ]);

    RatingOption::factory()->create([
        'rating_group_id' => $group->id,
        'label' => 'Option A',
        'label_translations' => null,
        'is_active' => true,
    ]);

    Livewire::test(RatingVoting::class, [
        'post' => $post,
        'groupKey' => $group->key,
    ])
        ->assertSee('Source')
        ->assertSee('Option A');
});
