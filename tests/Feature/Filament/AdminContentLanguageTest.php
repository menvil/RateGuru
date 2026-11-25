<?php

use App\Filament\Resources\Comments\Pages\ListComments;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;

/*
 * The language a post and a comment were read as, shown beside their number in the admin lists — or that none
 * was told yet.
 */

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create());
});

it('shows each post\'s language and how surely it was told', function () {
    $russian = Post::factory()->published()->create(['language' => 'ru', 'language_share' => 0.857]);
    $unknown = Post::factory()->published()->create(['language' => null]);

    $this->get(ListPosts::getUrl(['status' => 'all']))
        ->assertOk()
        ->assertSee('#'.$russian->id.' · ru 86%')
        ->assertSee('#'.$unknown->id.' · no language');
});

it('shows each comment\'s language, or that it tells none', function () {
    Comment::factory()->create(['language' => 'bg']);
    Comment::factory()->create(['language' => null]);

    $this->get(ListComments::getUrl())
        ->assertOk()
        ->assertSee('· bg')
        ->assertSee('· no language');
});
