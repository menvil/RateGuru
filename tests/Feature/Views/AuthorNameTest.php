<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

/*
 * An account's name is private once its owner has chosen a display name —
 * often the real name a sign-in provider gave. A post's author and a
 * comment's are shown by the name they chose, wherever they appear.
 */

it('shows a post\'s and a comment\'s author by the display name they chose, never the account name', function () {
    $author = User::factory()->create(['name' => 'Ada Realname', 'display_name' => 'Night Owl', 'username' => 'night_owl']);
    // Someone else's comment, so the post's author cannot stand in for its author.
    $commenter = User::factory()->create(['name' => 'Bea Realname', 'display_name' => 'Sea Fox', 'username' => 'sea_fox']);
    $post = Post::factory()->published()->for($author)->create(['title' => 'A quiet harbour']);
    Comment::factory()->for($post)->for($commenter)->create(['body' => 'And the mist.']);

    $this->get(route('feed'))->assertOk()->assertSee('Night Owl')->assertDontSee('Ada Realname');

    foreach ([$this->get(route('posts.show', $post))->assertOk(), Livewire::test(PostDrawer::class, ['postId' => $post->id])] as $page) {
        $page->assertSee('Night Owl')->assertSee('Sea Fox')
            ->assertDontSee('Ada Realname')->assertDontSee('Bea Realname');
    }
});
