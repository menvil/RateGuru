<?php

use App\Livewire\Profile\ProfilePage;
use App\Models\Post;
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
        'upvotes_count' => 100,
        'comments_count' => 100,
    ]);

    Livewire::test(ProfilePage::class, ['username' => 'chef_ivan'])
        ->assertSee('data-testid="profile-stats"', false)
        ->assertSee('Published posts')
        ->assertSee('2')
        ->assertSee('Total upvotes')
        ->assertSee('8')
        ->assertSee('Comments received')
        ->assertSee('3')
        ->assertDontSee('100');
});
