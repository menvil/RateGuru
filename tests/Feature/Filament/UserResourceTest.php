<?php

use App\Actions\Moderation\LimitUserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * People in Admin v2: the list — who may open it, what each tab holds and
 * how each row reads — and a person's page, where an administrator edits
 * their profile, role, trust and password from the top bar, a change of role
 * asked about first, while their status moves only through the moderation
 * actions.
 */
it('opens the list for administrators and moderators, and for nobody else', function (string $role, int $status) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'moderator' => User::factory()->moderator()->create(),
        default => User::factory()->create(),
    };

    $this->actingAs($user)->get(UserResource::getUrl('index'))->assertStatus($status);
})->with([
    'an administrator' => ['admin', 200],
    'a moderator' => ['moderator', 200],
    'a member' => ['user', 403],
]);

it('sits last in the Moderation section, with the list and a person\'s page', function () {
    expect(UserResource::getNavigationGroup())->toBe(AdminNavigationGroup::MODERATION)
        ->and(UserResource::getNavigationSort())->toBe(40)
        ->and(array_keys(UserResource::getPages()))->toBe(['index', 'edit']);
});

// One request per test: Filament reads the page's scope, the top bar's
// actions included, from the request the page was first rendered for.
it('draws the list in Admin v2', function () {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['username' => 'biscuit_mum']);

    expect($this->get(UserResource::getUrl('index'))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-users-title" class="rg-admin-page-header__title">Users</h1>')
        ->toContain('biscuit_mum')
        ->not->toContain('fi-ta-table');
});

it('draws a person\'s page in Admin v2, with Save changes in the top bar', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['username' => 'kotofey']);

    expect($this->get(EditUser::getUrl(['record' => $person]))->assertOk()->getContent())
        ->toContain('<h1 id="rg-admin-user-title" class="rg-admin-page-header__title">kotofey</h1>')
        ->toContain("\$dispatch('save-user')")
        ->toContain('Save changes')
        ->toContain('Moderation history')
        ->not->toContain('fi-section');
});

it('counts every tab, and reads each row: role, status, trust, posts and the reports on their content', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $person = User::factory()->create(['username' => 'cheap_pet_meds', 'name' => 'No name given', 'trust_level' => 0, 'status' => UserStatus::Shadowbanned]);
    $post = Post::factory()->published()->for($person)->create();
    reportOn($post);
    User::factory()->moderator()->create();

    $page = Livewire::test(ListUsers::class);
    $row = listedRow($page, $person);

    // The administrator, the person, the moderator and whoever made the report.
    expect(array_column($page->viewData('tabs'), 'count', 'id'))->toMatchArray(['all' => '4', 'active' => '3', 'shadowbanned' => '1'])
        ->and($page->viewData('stats'))->toBe(['total' => '4', 'restricted' => '1', 'staff' => '2'])
        ->and($row)->toMatchArray([
            'username' => 'cheap_pet_meds',
            'initials' => 'CH',
            'role' => 'User',
            'staff' => false,
            'trust' => 'Not trusted',
            'posts' => 1,
            'reports' => 1,
            'editUrl' => EditUser::getUrl(['record' => $person]),
            'postsUrl' => ListPosts::getUrl(['status' => 'all', 'q' => 'cheap_pet_meds']),
        ])
        ->and($row['meta'])->toStartWith('No name given · joined ')
        ->and($row['status'])->toBe(['label' => 'Shadowbanned', 'tone' => 'neutral', 'dot' => false]);
});

it('narrows the list to a tab, a role and the suspicious, and searches it on the server', function () {
    $this->actingAs(User::factory()->admin()->create());
    $moderator = User::factory()->moderator()->create(['username' => 'georgi_d']);
    $shadowbanned = User::factory()->shadowbanned()->create();

    $page = Livewire::test(ListUsers::class);

    expect(array_column($page->set('role', 'moderator')->viewData('rows'), 'id'))->toBe([$moderator->id])
        ->and(array_column($page->set('role', '')->set('suspicious', true)->viewData('rows'), 'id'))->toBe([$shadowbanned->id])
        ->and(array_column($page->set('suspicious', false)->set('q', 'georgi')->viewData('rows'), 'id'))->toBe([$moderator->id])
        ->and(array_column($page->set('q', '')->set('status', 'shadowbanned')->viewData('rows'), 'id'))->toBe([$shadowbanned->id]);

    $page->set('q', 'nobody like this')->assertSeeHtml('No people match')->call('clearFilters')->assertSet('q', '');
});

it('opens the suspicious from the dashboard', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::withQueryParams(['suspicious' => 1])->test(ListUsers::class)
        ->assertSet('suspicious', true)
        ->assertSeeHtml('Suspicious: shadowbanned or reported content');
});

it('saves the profile, the role, the trust and a new password from the top bar, the role change asked about first', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $person = User::factory()->create(['name' => 'Old Name', 'username' => 'old_name', 'email' => 'old@example.com', 'trust_level' => 10]);

    $page = Livewire::test(EditUser::class, ['record' => $person->id])
        ->set('name', '  New   Name ')
        ->set('username', 'new_name')
        ->set('email', 'NEW@example.com')
        ->set('role', 'moderator')
        ->set('trust', 'reviewed')
        ->set('password', 'a-long-new-password-2026')
        ->dispatch('save-user')
        ->assertHasNoErrors()
        ->assertSet('confirmingRole', true)
        ->assertSeeHtml('Change role to Moderator?');

    expect($person->fresh()->role)->toBe(UserRole::User);

    $page->call('save')
        ->assertSet('confirmingRole', false)
        ->assertDispatched('rg-admin-toast', message: 'Changes to new_name saved')
        ->assertSet('password', '')
        ->assertSet('saved.role', 'moderator');

    $fresh = $person->fresh();

    expect($fresh)->name->toBe('New Name')->username->toBe('new_name')->email->toBe('new@example.com')->role->toBe(UserRole::Moderator)->trust_level->toBe(0)->status->toBe(UserStatus::Active)
        ->and(Hash::check('a-long-new-password-2026', $fresh->password))->toBeTrue()
        ->and(ModerationLog::query()->count())->toBe(0);
});

it('says what to change when the draft cannot be saved', function (array $draft, string $field) {
    $this->actingAs(User::factory()->admin()->create());
    User::factory()->create(['username' => 'taken_name', 'email' => 'taken@example.com']);
    $person = User::factory()->create();

    $page = Livewire::test(EditUser::class, ['record' => $person->id]);

    foreach ($draft as $property => $value) {
        $page->set($property, $value);
    }

    $page->dispatch('save-user')->assertHasErrors([$field]);
})->with([
    'a username in use' => [['username' => 'taken_name'], 'username'],
    'a username with capitals' => [['username' => 'Not Allowed'], 'username'],
    'an email address in use' => [['email' => 'taken@example.com'], 'email'],
    'no name' => [['name' => ''], 'name'],
    'a role that does not exist' => [['role' => 'owner'], 'role'],
]);

it('puts the saved values back on Discard', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['name' => 'Kept Name']);

    Livewire::test(EditUser::class, ['record' => $person->id])
        ->set('name', 'Something else')
        ->set('password', 'typed-but-discarded')
        ->dispatch('discard-user')
        ->assertSet('name', 'Kept Name')
        ->assertSet('password', '');
});

it('keeps what is saved and the person it edits on the server', function (string $property, mixed $value) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(EditUser::class, ['record' => User::factory()->create()->id])->set($property, $value);
})->throws(CannotUpdateLockedPropertyException::class)
    ->with([
        'what is saved' => ['saved', ['role' => 'admin']],
        'the person' => ['userId', 1],
        'the confirmation' => ['sanction', ['kind' => 'ban', 'id' => 1]],
    ]);

it('lets nobody but an administrator edit someone else\'s account, never another administrator\'s', function (string $case) {
    $actor = $case === 'a moderator' ? User::factory()->moderator()->create() : User::factory()->admin()->create();
    $target = match ($case) {
        'another administrator' => User::factory()->admin()->create(),
        'their own account' => $actor,
        default => User::factory()->create(),
    };

    $this->actingAs($actor)->get(EditUser::getUrl(['record' => $target]))->assertForbidden();
})->with(['a moderator', 'another administrator', 'their own account']);

it('shows where the person stands: the decision behind their status, their account and their moderation history', function () {
    $admin = User::factory()->admin()->create(['name' => 'Daria Kovaleva']);
    $this->actingAs($admin);
    $person = User::factory()->create(['locale' => 'en']);
    app(LimitUserAction::class)->handle($admin, $person, 'Repeated vitamin promos.');

    $page = Livewire::test(EditUser::class, ['record' => $person->id]);
    $view = $page->viewData('person');
    $history = $page->viewData('history');

    expect($view['since'])->toStartWith('Since Today')->toEndWith('by Daria Kovaleva')
        ->and($view['reason'])->toBe('Repeated vitamin promos.')
        ->and($view['signIn'])->toBe('Email and password')
        ->and($history[0])->toMatchArray(['icon' => 'shield-alert', 'title' => 'Limited by Daria Kovaleva', 'body' => 'Reason: Repeated vitamin promos.'])
        ->and(end($history)['title'])->toBe('Account created')
        ->and($page->viewData('options')['restore'])->toBeTrue();
});

it('opens a person found by global search on their page, or the list where the page is not theirs to edit', function () {
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->create(['username' => 'biscuit_mum']);
    $otherAdmin = User::factory()->admin()->create(['username' => 'daria_k']);

    expect(UserResource::getGlobalSearchResultUrl($member))->toBe(EditUser::getUrl(['record' => $member]))
        ->and(UserResource::getGlobalSearchResultUrl($otherAdmin))->toBe(ListUsers::getUrl(['q' => 'daria_k']));
});
