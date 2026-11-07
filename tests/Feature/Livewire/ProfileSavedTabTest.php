<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\Post;
use App\Models\PostSave;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Profile\ProfileStats;
use App\Support\Settings\ProjectSettingsManager;
use Livewire\Livewire;

/*
 * Saved posts are a feature the project switches on; these are about the
 * feature, so it is on.
 */
beforeEach(function () {
    ProjectSettings::factory()->create(['feature_flags' => ['show_saved_posts' => true]]);
});

it('shows saved posts tab content to owner', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Saved In Profile']);

    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $this->actingAs($owner)
        ->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertSee('Saved In Profile');
});

it('does not show saved posts tab content to other users', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Private Saved Post']);
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $viewer = User::factory()->create();

    $this->actingAs($viewer)
        ->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertDontSee('data-testid="profile-saved-tab"', false);
});

it('does not show saved posts tab content to guests', function () {
    $owner = User::factory()->create(['username' => 'owner']);
    $post = Post::factory()->published()->create(['title' => 'Owner Only Post']);
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => $post->id]);

    $this->get(route('profile.show', ['username' => $owner->username, 'tab' => 'saved']))
        ->assertOk()
        ->assertDontSee('data-testid="profile-saved-tab"', false);
});

it('offers no saved tab and no saved count while saved posts are switched off', function () {
    ProjectSettings::query()->sole()->update(['feature_flags' => ['show_saved_posts' => false]]);
    app(ProjectSettingsManager::class)->flush();
    $owner = User::factory()->create();

    Livewire::actingAs($owner)
        ->test(ProfilePage::class, ['username' => $owner->username])
        ->assertDontSee('data-testid="profile-tab-saved"', false)
        ->call('setTab', 'saved')
        ->assertSet('tab', 'posts');

    expect(app(ProfileStats::class)->forUser($owner, $owner)->savedPostsCount)->toBeNull();
});

it('shows the posts, from their first page, once saved posts are switched off under an open saved tab', function () {
    $owner = User::factory()->create();
    PostSave::factory()->create(['user_id' => $owner->id, 'post_id' => Post::factory()->published()->create()->id]);

    $profile = Livewire::actingAs($owner)
        ->test(ProfilePage::class, ['username' => $owner->username])
        ->call('setTab', 'saved')
        ->call('gotoPage', 2)
        ->assertSet('tab', 'saved');

    ProjectSettings::query()->sole()->update(['feature_flags' => ['show_saved_posts' => false]]);
    app(ProjectSettingsManager::class)->flush();

    $profile->call('gotoPage', 3)
        ->assertSet('tab', 'posts')
        ->assertSet('paginators.page', 1)
        ->assertSee('data-testid="profile-posts-tab"', false);
});
