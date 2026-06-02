<?php

use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
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

it('allows admin to edit user identity and password', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old-user@example.com',
        'status' => UserStatus::Active,
    ]);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => 'New Name',
            'username' => 'new_username',
            'email' => 'new-user@example.com',
            'role' => $user->role->value,
            'status' => UserStatus::Limited->value,
            'password' => 'new-password',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $user->fresh();

    expect($fresh->name)->toBe('New Name')
        ->and($fresh->username)->toBe('new_username')
        ->and($fresh->email)->toBe('new-user@example.com')
        ->and($fresh->status)->toBe(UserStatus::Limited)
        ->and(Hash::check('new-password', $fresh->password))->toBeTrue();
});

it('validates username uniqueness when editing a user', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(['username' => 'taken_username']);
    $user = User::factory()->create(['username' => 'original_username']);

    $this->actingAs($admin);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->fillForm([
            'name' => $user->name,
            'username' => 'taken_username',
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
        ])
        ->call('save')
        ->assertHasFormErrors(['username' => 'unique']);
});

it('does not allow moderators to edit users directly', function () {
    $moderator = User::factory()->moderator()->create();
    $user = User::factory()->create(['status' => UserStatus::Active]);

    $this->actingAs($moderator);

    Livewire::test(EditUser::class, ['record' => $user->getRouteKey()])
        ->assertForbidden();
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

it('renders in the flat admin navigation', function () {
    expect(UserResource::getNavigationGroup())->toBeNull();
});

it('exposes index and edit pages', function () {
    expect(array_keys(UserResource::getPages()))->toBe(['index', 'edit']);
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

    // Assert the cell state for $author's posts_count column comes from
    // withCount('posts') and resolves to 2 — this exercises the table
    // rendering path, not just the underlying loadCount().
    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$author])
        ->assertTableColumnExists('posts_count')
        ->assertCanRenderTableColumn('posts_count')
        ->assertTableColumnStateSet('posts_count', 2, $author);
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
