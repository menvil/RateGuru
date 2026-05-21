<?php

use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to delete unused tag from tag resource', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Unused',
        'slug' => 'unused',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->callTableAction('delete', $tag);

    $this->assertDatabaseMissing('tags', [
        'id' => $tag->id,
    ]);
});

it('does not allow deleting tag attached to posts', function () {
    $admin = User::factory()->admin()->create();

    $tag = Tag::factory()->create([
        'name' => 'Used',
        'slug' => 'used',
    ]);

    $post = Post::factory()->published()->create();
    $post->tags()->attach($tag);

    $this->actingAs($admin);

    Livewire::test(ListTags::class)
        ->callTableAction('delete', $tag);

    $this->assertDatabaseHas('tags', [
        'id' => $tag->id,
    ]);

    expect($post->fresh()->tags()->whereKey($tag->id)->exists())->toBeTrue();
});

it('does not show delete tag action to moderator', function () {
    $moderator = User::factory()->moderator()->create();
    $tag = Tag::factory()->create();

    $this->actingAs($moderator);

    Livewire::test(ListTags::class)
        ->assertTableActionHidden('delete', $tag);
});
