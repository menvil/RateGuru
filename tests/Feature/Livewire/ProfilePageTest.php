<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

it('can render profile page component', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertStatus(200);
});

it('fails profile page component for missing username', function () {
    expect(fn () => Livewire::test(ProfilePage::class, ['username' => 'missing_user']))
        ->toThrow(ModelNotFoundException::class);
});

it('renders selected user in profile page component', function () {
    User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('chef_ivan')
        ->assertSee('Ivan Chef');
});

it('renders profile header section', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-header"', false);
});

it('renders user avatar on profile page', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => 'https://example.test/avatar.jpg',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false)
        ->assertSee('https://example.test/avatar.jpg', false);
});

it('renders avatar fallback when user has no avatar url', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
        'avatar_url' => null,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-avatar"', false);
});

it('renders username and display name on profile page', function () {
    User::factory()->create([
        'name' => 'Ivan Chef',
        'username' => 'chef_ivan',
        'email' => 'ivan@example.test',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-identity"', false)
        ->assertSee('Ivan Chef')
        ->assertSee('@chef_ivan')
        ->assertDontSee('ivan@example.test');
});

it('renders username when display name is empty', function () {
    User::factory()->create([
        'name' => '',
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('@chef_ivan')
        ->assertSee('chef_ivan');
});

it('renders public user stats on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'upvotes_count' => 5,
        'comments_count' => 2,
    ]);

    Post::factory()->for($user)->published()->create([
        'upvotes_count' => 3,
        'comments_count' => 1,
    ]);

    Post::factory()->for($user)->hidden()->create([
        'title' => 'Hidden stats trap',
        'upvotes_count' => 999999,
        'comments_count' => 999999,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-stats"', false)
        ->assertSee('Posts')
        ->assertSee('2')
        ->assertDontSee('999999')
        ->assertDontSee('Hidden stats trap');
});

it('renders user published posts grid on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Published sample post',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-posts-grid"', false)
        ->assertSee('Published sample post');
});

it('renders profile posts as full feed post cards with the same controls as the feed', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Published sample post',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="post-card"', false)
        ->assertSee('data-testid="post-card-voting"', false);
});

it('renders generic profile copy', function () {
    $user = User::factory()->create([
        'username' => 'alice',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Generic profile post',
        'image_path' => null,
        'image_url' => null,
    ]);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('Posts')
        ->assertSee('Generic profile post')
        ->assertDontSee('Dishes')
        ->assertDontSee('Food')
        ->assertDontSee('Food image');
});

it('renders empty state when user has no published posts', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('No published posts yet');
});

it('only shows published posts on profile page', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Post::factory()->for($user)->published()->create([
        'title' => 'Published post',
    ]);

    Post::factory()->for($user)->pending()->create([
        'title' => 'Pending post',
    ]);

    Post::factory()->for($user)->hidden()->create([
        'title' => 'Hidden post',
    ]);

    Post::factory()->for($user)->rejected()->create([
        'title' => 'Rejected post',
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Published post')
        ->assertDontSee('Pending post')
        ->assertDontSee('Hidden post')
        ->assertDontSee('Rejected post');
});

it('does not show other users posts on profile page', function () {
    $profileUser = User::factory()->create(['username' => 'chef_ivan']);
    $otherUser = User::factory()->create(['username' => 'other']);

    Post::factory()->for($profileUser)->published()->create(['title' => 'Own post']);
    Post::factory()->for($otherUser)->published()->create(['title' => 'Other post']);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Own post')
        ->assertDontSee('Other post');
});

it('shows edit profile link to profile owner', function () {
    $user = User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    Livewire::actingAs($user)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Edit profile')
        ->assertSee('data-testid="edit-profile-link"', false)
        ->assertSee(route('profile.edit'), false);
});

it('does not show edit profile link to other users', function () {
    User::factory()->create(['username' => 'chef_ivan']);
    $viewer = User::factory()->create(['username' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('Edit profile')
        ->assertDontSee('data-testid="edit-profile-link"', false);
});

it('does not show edit profile link to guest', function () {
    User::factory()->create(['username' => 'chef_ivan']);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('Edit profile')
        ->assertDontSee('data-testid="edit-profile-link"', false);
});

it('shows report user placeholder to authenticated non owner', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $viewer = User::factory()->create([
        'username' => 'viewer',
    ]);

    Livewire::actingAs($viewer)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="report-user-button"', false);
});

it('does not show report user placeholder to profile owner', function () {
    $owner = User::factory()->create(['username' => 'chef_ivan']);

    Livewire::actingAs($owner)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('data-testid="report-user-button"', false);
});

it('does not show report user placeholder to guest', function () {
    User::factory()->create(['username' => 'chef_ivan']);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertDontSee('data-testid="report-user-button"', false);
});

it('does not create reports from report user placeholder', function () {
    User::factory()->create([
        'username' => 'chef_ivan',
    ]);

    $viewer = User::factory()->create([
        'username' => 'viewer',
    ]);

    Livewire::actingAs($viewer)
        ->test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('Report user');

    expect(Report::query()->count())->toBe(0);
});
