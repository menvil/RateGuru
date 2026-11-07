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
    $post = Post::factory()->published()->for($author)->create(['title' => 'A quiet harbour']);
    Comment::factory()->for($post)->for($author)->create(['body' => 'And the mist.']);

    foreach ([route('posts.show', $post), route('feed')] as $url) {
        $this->get($url)->assertOk()->assertSee('Night Owl')->assertDontSee('Ada Realname');
    }

    Livewire::test(PostDrawer::class, ['postId' => $post->id])->assertSee('Night Owl')->assertDontSee('Ada Realname');
});
