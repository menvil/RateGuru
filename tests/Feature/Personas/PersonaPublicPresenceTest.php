<?php

use App\Enums\UserRole;
use App\Livewire\Feed\PostDrawer;
use App\Livewire\Profile\ProfilePage;
use App\Models\Comment;
use App\Models\PersonaProfile;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

/**
 * A Persona on the public site is an account like any other: its posts, its
 * comments and its profile are drawn exactly as a member's, and nothing on
 * any page — no badge, no label, no hint — says it is a Persona. Only
 * administrators know, in Content Studio.
 */
beforeEach(function () {
    $this->freezeTime();

    $this->persona = User::factory()->persona()->create([
        'username' => 'velvet_footnote',
        'name' => 'Velvet Footnote',
        'display_name' => 'Velvet Footnote',
    ]);
    PersonaProfile::factory()->create(['user_id' => $this->persona->id, 'character' => 'INTERNAL-CHARACTER']);
});

it('draws a persona’s post card and comment exactly as a member’s', function () {
    $post = Post::factory()->published()->for($this->persona, 'user')->make(['title' => 'A harbour at dawn'])->setRelation('user', $this->persona);
    $comment = Comment::factory()->for($this->persona, 'user')->make(['body' => 'The gulls have opinions.'])->setRelation('user', $this->persona);
    $render = fn (): array => [
        Blade::render('<x-feed.post-card :post="$post" />', ['post' => $post]),
        Blade::render('<x-comments.comment-item :comment="$comment" />', ['comment' => $comment]),
    ];

    $asPersona = $render();
    $this->persona->forceFill(['role' => UserRole::User]);

    expect($asPersona)->toBe($render())
        ->and($asPersona[0])->toContain('@velvet_footnote')
        ->and($asPersona[1])->toContain('@velvet_footnote');
});

it('says nothing of a persona on the feed, the post’s page, its drawer or its profile', function (string $surface) {
    $post = Post::factory()->published()->for($this->persona, 'user')->create(['title' => 'A harbour at dawn']);

    $html = match ($surface) {
        'feed' => $this->get(route('feed'))->assertOk()->getContent(),
        'post' => $this->get(route('posts.show', $post))->assertOk()->getContent(),
        'drawer' => Livewire::test(PostDrawer::class, ['postId' => $post->id])->html(),
        'profile' => Livewire::test(ProfilePage::class, ['username' => 'velvet_footnote'])->html(),
    };

    expect($html)->toContain('@velvet_footnote')
        ->and(mb_strtolower($html))->not->toContain('persona')
        ->and($html)->not->toContain('INTERNAL-CHARACTER');
})->with(['feed', 'post', 'drawer', 'profile']);
