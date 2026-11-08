<?php

use App\Livewire\Feed\PostDrawer;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Livewire;

/*
 * Guest viewing off in Project settings: the project's content is for
 * signed-in people only — the feed, a post, its share links and a profile,
 * on a page and in every request a component drawn on one makes. The legal
 * and contact pages stay open.
 */

function guestViewing(bool $on): void
{
    ProjectSettings::query()->updateOrCreate(['id' => 1], ['feature_flags' => ['allow_guest_viewing' => $on]]);
    app(ProjectSettingsManager::class)->flush();
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->author = User::factory()->create(['username' => 'night_owl']);
    $this->post = Post::factory()->published()->for($this->author)->create();
});

it('sends a guest to sign in for the content once guest viewing is off', function () {
    guestViewing(false);

    foreach ([route('feed'), route('posts.show', $this->post), route('posts.share', [$this->post, 'x']), route('profile.show', 'night_owl')] as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    foreach (['pages.about', 'pages.privacy', 'pages.terms', 'pages.contact'] as $page) {
        $this->get(route($page))->assertOk();
    }
});

it('shows the content to a guest while guest viewing is on, and to anyone signed in either way', function () {
    guestViewing(true);
    $this->get(route('feed'))->assertOk();
    $this->get(route('posts.show', $this->post))->assertOk();

    guestViewing(false);
    $this->actingAs(User::factory()->create());
    $this->get(route('feed'))->assertOk();
    $this->get(route('posts.show', $this->post))->assertOk();
    $this->get(route('profile.show', 'night_owl'))->assertOk();
});

it('answers nothing to a guest from a feed drawn before guest viewing went off', function () {
    guestViewing(true);
    $snapshot = pageComponentSnapshot($this->get(route('feed'))->assertOk()->getContent(), 'feed.feed-page');

    guestViewing(false);

    $this->withHeaders(['X-Livewire' => '1'])
        ->postJson(Livewire::getUpdateUri(), [
            'components' => [['snapshot' => $snapshot, 'updates' => ['search' => 'night'], 'calls' => []]],
        ])
        ->assertUnauthorized();
});

it('shows a guest no post through the panel the static pages draw too, once guest viewing is off', function () {
    guestViewing(false);

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->call('setSelectedPost', $this->post->id)
        ->assertDontSee($this->post->title);

    $this->actingAs(User::factory()->create());

    Livewire::test(PostDrawer::class, ['asOverlay' => true])
        ->call('setSelectedPost', $this->post->id)
        ->assertSee($this->post->title);
});
