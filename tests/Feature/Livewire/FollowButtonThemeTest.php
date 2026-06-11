<?php

use App\Livewire\Follows\FollowButton;
use App\Models\ProjectSettings;
use App\Models\User;
use Livewire\Livewire;

it('renders follow button with theme token classes', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    $html = Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->html();

    expect($html)->toContain('bg-rg-card');
    expect($html)->toContain('border-rg-border2');
    expect($html)->toContain('text-rg-text2');
});

it('renders following state with accent background token', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    \App\Models\Follow::factory()->create([
        'follower_id' => $viewer->id,
        'author_id' => $author->id,
    ]);

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->assertSee('bg-rg-accent', false);
});

it('follow button uses accessible aria attributes', function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_follow_buttons' => true]]);

    $viewer = User::factory()->create();
    $author = User::factory()->create();

    Livewire::actingAs($viewer)
        ->test(FollowButton::class, ['author' => $author])
        ->assertSee('aria-pressed', false)
        ->assertSee('data-testid="follow-button"', false);
});
