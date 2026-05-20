<?php

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\Post;
use App\Models\User;
use Livewire\Livewire;

it('allows admin to access user resource index', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk();
});

it('allows moderator to access user resource index', function () {
    $moderator = User::factory()->moderator()->create();

    $this->actingAs($moderator)
        ->get(UserResource::getUrl('index'))
        ->assertOk();
});

it('does not allow normal user to access user resource index', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(UserResource::getUrl('index'))
        ->assertForbidden();
});

it('uses the User model', function () {
    expect(UserResource::getModel())->toBe(User::class);
});

it('lives under the Users navigation group', function () {
    expect(UserResource::getNavigationGroup())->toBe(AdminNavigationGroup::USERS);
});

it('does not expose create or edit pages in this phase', function () {
    expect(array_keys(UserResource::getPages()))->toBe(['index']);
});

it('lists users on the index page', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$admin, $other]);
});

it('renders a searchable, sortable username column', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['username' => 'chef_ivan']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnExists('username')
        ->assertCanRenderTableColumn('username')
        ->assertSee('chef_ivan');
});

it('renders a searchable, sortable email column', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnExists('email')
        ->assertCanRenderTableColumn('email')
        ->assertSee('user@example.com');
});

it('renders a reports count placeholder column', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['username' => 'reported_user_placeholder']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertTableColumnExists('reports_count_placeholder')
        ->assertCanRenderTableColumn('reports_count_placeholder')
        ->assertSee('—');
});

it('renders a posts count column', function () {
    $admin = User::factory()->admin()->create();
    $author = User::factory()->create(['username' => 'poster']);
    Post::factory()->count(2)->for($author)->published()->create();

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$author])
        ->assertTableColumnExists('posts_count')
        ->assertCanRenderTableColumn('posts_count');

    expect($author->loadCount('posts')->posts_count)->toBe(2);
});

it('renders a sortable status badge column', function () {
    $admin = User::factory()->admin()->create();
    $banned = User::factory()->banned()->create(['username' => 'banned_user']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$banned])
        ->assertTableColumnExists('status')
        ->assertCanRenderTableColumn('status')
        ->assertSee('banned');
});

it('renders a sortable role badge column', function () {
    $admin = User::factory()->admin()->create();
    $moderator = User::factory()->moderator()->create(['username' => 'mod_user']);

    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$moderator])
        ->assertTableColumnExists('role')
        ->assertCanRenderTableColumn('role')
        ->assertSee('moderator');
});
