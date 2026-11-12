<?php

use App\Actions\Moderation\LimitUserAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\AdminNavigationGroup;
use App\Livewire\Admin\UserEditor;
use App\Models\ModerationLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * People in Admin v2: the list — who may open it, what each tab holds and
 * how each row reads — and a person's editor in the drawer over it, where an
 * administrator edits their profile, role, trust and password, saved from
 * the drawer's footer with a change of role asked about first, while their
 * status moves only through the moderation actions.
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

it('sits last in the Moderation section, with the list and a person\'s old address', function () {
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

it('draws a person\'s editor in the drawer over the list, with Save changes in its footer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['username' => 'kotofey']);

    expect($this->get(ListUsers::getUrl(['user' => $person->id]))->assertOk()->getContent())
        ->toContain('id="rg-admin-user-editor"')
        ->toContain('rg-admin-drawer--editor')
        ->toContain('kotofey')
        ->toContain('Save changes')
        ->toContain('Moderation history')
        ->toContain('<h1 id="rg-admin-users-title" class="rg-admin-page-header__title">Users</h1>')
        ->not->toContain('fi-section');
});

it('leads a person\'s old address to their drawer, opened from the row and closed again', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create();

    $this->get(EditUser::getUrl(['record' => $person]))->assertRedirect(ListUsers::getUrl(['user' => $person->id]));

    Livewire::test(ListUsers::class)
        ->call('editUser', $person->id)
        ->assertSet('editing', (string) $person->id)
        ->assertSeeLivewire(UserEditor::class)
        ->dispatch('close-user')
        ->assertSet('editing', '')
        ->assertDontSeeLivewire(UserEditor::class);

    Livewire::withQueryParams(['user' => $person->id])->test(ListUsers::class)
        ->dispatch('user-saved', message: 'Changes to '.$person->username.' saved')
        ->assertSet('editing', '')
        ->assertDispatched('rg-admin-toast', message: 'Changes to '.$person->username.' saved', tone: 'success');
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
            'editUrl' => ListUsers::getUrl(['user' => $person->id]),
            'postsUrl' => ListPosts::getUrl(['status' => 'all', 'q' => 'cheap_pet_meds']),
        ])
        ->and($row['meta'])->toStartWith('No name given · joined ')
        ->and($row['status'])->toBe(['label' => 'Shadowbanned', 'tone' => 'neutral', 'dot' => false]);
});

it('narrows the list to a tab, a role and the suspicious, and searches it on the server', function () {
    // Every searched field set, so no generated name, username or email
    // matches the search by chance: Faker's names include Georgiana.
    $this->actingAs(User::factory()->admin()->create(['username' => 'desk_admin', 'name' => 'Desk Admin', 'email' => 'admin@example.com']));
    $moderator = User::factory()->moderator()->create(['username' => 'georgi_d', 'name' => 'Desk Moderator', 'email' => 'moderator@example.com']);
    $shadowbanned = User::factory()->shadowbanned()->create(['username' => 'elena_p', 'name' => 'Elena Popova', 'email' => 'elena@example.com']);

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

it('saves nothing from a drawer opened before the account changed, and shows it as it is now', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->moderator()->create(['name' => 'Old Name']);

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);

    // Another administrator takes the moderator role away meanwhile.
    User::query()->whereKey($person->id)->update(['role' => 'user']);

    $page->set('name', 'New Name')
        ->call('save')
        ->assertDispatched('rg-admin-toast', message: 'This account was changed while you were editing it, so nothing was saved. Its current details are shown.', tone: 'error')
        ->assertSet('role', 'user')
        ->assertSet('name', 'Old Name');

    expect($person->fresh())->role->toBe(UserRole::User)->name->toBe('Old Name');
});

it('says an account changed meanwhile, not that its old address is taken, when another account took the address it left', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);

    // The owner moves to a new address, and another account takes the old one.
    User::query()->whereKey($person->id)->update(['email' => 'new@example.com']);
    User::factory()->create(['email' => 'old@example.com']);

    $page->set('name', 'New Name')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('rg-admin-toast', message: 'This account was changed while you were editing it, so nothing was saved. Its current details are shown.', tone: 'error')
        ->assertSet('email', 'new@example.com');

    expect($person->fresh())->name->toBe('Old Name')->email->toBe('new@example.com');
});

it('shows a username or address taken by another account between the check and the save as that field\'s error', function (string $field, string $value, string $message) {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create();
    $other = User::factory()->create();

    // Another account claims the value right after the drawer's own check
    // passed: the first row lock the save takes is the moment in between.
    $claimed = false;
    DB::listen(function (QueryExecuted $query) use (&$claimed, $other, $field, $value): void {
        if (! $claimed && str_contains($query->sql, 'for update')) {
            $claimed = true;
            User::query()->whereKey($other->id)->update([$field => $value]);
        }
    });

    Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->set($field, $value)
        ->call('save')
        ->assertHasErrors([$field])
        ->assertSee($message);

    expect($claimed)->toBeTrue()
        ->and($person->fresh()->{$field})->not->toBe($value);
})->with([
    'username' => ['username', 'claimed_name', 'This username is taken. Choose another one.'],
    'email' => ['email', 'claimed@example.com', 'Another account uses this email address.'],
])->skip(
    fn (): bool => config('database.default') === 'sqlite',
    'SQLite takes no row locks — `lockForUpdate()` adds nothing to its SQL — so there is no moment in between to change anything in.',
);

it('saves the profile, the role, the trust and a new password from the drawer, the role change asked about first', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);
    $person = User::factory()->create(['name' => 'Old Name', 'username' => 'old_name', 'email' => 'old@example.com', 'trust_level' => 10]);

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->set('name', '  New   Name ')
        ->set('username', 'new_name')
        ->set('email', 'NEW@example.com')
        ->set('role', 'moderator')
        ->set('trust', 'reviewed')
        ->set('password', 'a-long-new-password-2026')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('confirmingRole', true)
        ->assertSeeHtml('Change role to Moderator?');

    expect($person->fresh()->role)->toBe(UserRole::User);

    $page->call('save')
        ->assertSet('confirmingRole', false)
        ->assertDispatched('user-saved', message: 'Changes to new_name saved')
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

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);

    foreach ($draft as $property => $value) {
        $page->set($property, $value);
    }

    $page->call('save')->assertHasErrors([$field]);
})->with([
    'a username in use' => [['username' => 'taken_name'], 'username'],
    'a username with capitals' => [['username' => 'Not Allowed'], 'username'],
    'an email address in use' => [['email' => 'taken@example.com'], 'email'],
    'no name' => [['name' => ''], 'name'],
    'a role that does not exist' => [['role' => 'owner'], 'role'],
]);

it('saves nothing when the drawer is closed with Cancel', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['name' => 'Kept Name']);

    Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->set('name', 'Something else')
        ->assertSeeHtml('x-on:dismiss="$wire.dispatch(\'close-user\')"');

    Livewire::withQueryParams(['user' => $person->id])->test(ListUsers::class)->dispatch('close-user')->assertSet('editing', '');

    expect($person->fresh()->name)->toBe('Kept Name');
});

it('keeps what is saved and the person it edits on the server', function (string $property, mixed $value) {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(UserEditor::class, ['userId' => User::factory()->create()->id])->set($property, $value);
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

    Livewire::test(UserEditor::class, ['userId' => $target->id])->assertForbidden();
    Livewire::withQueryParams(['user' => $target->id])->test(ListUsers::class)->assertSet('editing', '')->assertDontSeeLivewire(UserEditor::class);
})->with(['a moderator', 'another administrator', 'their own account']);

it('shows where the person stands: the decision behind their status, their account and their moderation history', function () {
    $admin = User::factory()->admin()->create(['name' => 'Daria Kovaleva']);
    $this->actingAs($admin);
    $person = User::factory()->create(['locale' => 'en']);
    app(LimitUserAction::class)->handle($admin, $person, 'Repeated vitamin promos.');

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);
    $view = $page->viewData('person');
    $history = $page->viewData('history');

    expect($view['since'])->toStartWith('Since Today')->toEndWith('by Daria Kovaleva')
        ->and($view['reason'])->toBe('Repeated vitamin promos.')
        ->and($view['signIn'])->toBe('Email and password')
        ->and($history[0])->toMatchArray(['icon' => 'shield-alert', 'title' => 'Limited by Daria Kovaleva', 'body' => 'Reason: Repeated vitamin promos.'])
        ->and(end($history)['title'])->toBe('Account created')
        ->and($page->viewData('options')['restore'])->toBeTrue();
});

it('opens a person found by global search in their drawer, or the list where they are not the viewer\'s to edit', function () {
    $this->actingAs(User::factory()->admin()->create());
    $member = User::factory()->create(['username' => 'biscuit_mum']);
    $otherAdmin = User::factory()->admin()->create(['username' => 'daria_k']);

    expect(UserResource::getGlobalSearchResultUrl($member))->toBe(ListUsers::getUrl(['user' => $member->id]))
        ->and(UserResource::getGlobalSearchResultUrl($otherAdmin))->toBe(ListUsers::getUrl(['q' => 'daria_k']));
});

/*
 * A Persona among the people: listed and found by its role, never counted
 * as staff, its page open to show its account but never to edit it — while
 * the moderation actions treat it as they treat a member.
 */
it('lists a persona with its role, never as staff', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create(['username' => 'chef_persona']);
    User::factory()->moderator()->create();

    $page = Livewire::test(ListUsers::class);

    expect(listedRow($page, $persona))->toMatchArray([
        'username' => 'chef_persona',
        'role' => 'Persona',
        'staff' => false,
        'editUrl' => ListUsers::getUrl(['user' => $persona->id]),
    ])
        ->and($page->viewData('stats')['staff'])->toBe('2');
});

it('narrows the list to personas, from the role filter or a link', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create();
    User::factory()->create();

    $page = Livewire::test(ListUsers::class);

    expect($page->viewData('roles'))->toContain(['value' => 'persona', 'label' => 'Persona'])
        ->and(array_column($page->set('role', 'persona')->viewData('rows'), 'id'))->toBe([$persona->id])
        ->and($page->viewData('roleName'))->toBe('Persona');

    Livewire::withQueryParams(['role' => 'persona'])->test(ListUsers::class)
        ->assertSet('role', 'persona')
        ->assertSeeHtml('Role: Persona');
});

it('draws personas in every state, the deleted one included', function () {
    $this->actingAs(User::factory()->admin()->create());

    foreach (['active', 'limited', 'banned', 'shadowbanned'] as $state) {
        User::factory()->persona()->create(['username' => 'persona_'.$state, 'status' => UserStatus::from($state)]);
    }

    User::factory()->persona()->tombstoned()->create();

    expect($this->get(UserResource::getUrl('index', ['role' => 'persona']))->assertOk()->getContent())
        ->toContain('persona_active')
        ->toContain('persona_limited')
        ->toContain('persona_banned')
        ->toContain('persona_shadowbanned')
        ->toContain('Removed on deletion');
});

it('reads each persona row by its state, the deleted one read-only', function () {
    $this->actingAs(User::factory()->admin()->create());
    $personas = collect(['Active', 'Limited', 'Banned', 'Shadowbanned'])
        ->mapWithKeys(fn (string $label): array => [$label => User::factory()->persona()->create(['status' => UserStatus::from(strtolower($label))])]);
    $deleted = User::factory()->persona()->tombstoned()->create();

    $page = Livewire::test(ListUsers::class)->set('role', 'persona');

    foreach ($personas as $label => $persona) {
        expect(listedRow($page, $persona))->status->label->toBe($label)->staff->toBeFalse();
    }

    expect(listedRow($page, $deleted))->toMatchArray(['deleted' => true, 'email' => 'Removed on deletion', 'editUrl' => null]);
});

it('opens a persona\'s drawer, showing its account and role with nothing to edit', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create(['username' => 'chef_persona']);

    $this->get(ListUsers::getUrl(['user' => $persona->id]))->assertOk()
        ->assertSee('<h2 id="rg-admin-user-editor-title" class="rg-admin-drawer__title">chef_persona</h2>', false);

    expect(Livewire::test(UserEditor::class, ['userId' => $persona->id])->html())
        ->toContain('A persona’s account can’t be edited here.')
        ->toContain($persona->email)
        ->toMatch('/rg-admin-user__role">(?:\s|<!--.*?-->)*Persona\s*</')
        ->not->toContain('wire:model="name"')
        ->not->toContain('wire:model="email"')
        ->not->toContain('wire:model="role"')
        ->not->toContain('wire:model="trust"')
        ->not->toContain('New password')
        ->not->toContain('Mark trusted')
        ->not->toContain('Save changes');
});

/*
 * Nothing in a Persona's drawer is a draft, and a forged request changes
 * nothing either: UpdateUserAccountAction refuses the account, and the
 * drawer says why.
 */
it('saves nothing on a persona\'s drawer, whatever the request carries', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create(['name' => 'Kept Name', 'trust_level' => 10]);
    $email = $persona->email;

    Livewire::test(UserEditor::class, ['userId' => $persona->id])
        ->assertSet('role', 'persona')
        ->set('name', 'Forged Name')
        ->set('email', 'real@example.com')
        ->set('trust', 'reviewed')
        ->set('password', 'a-long-new-password-2026')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('confirmingRole', false)
        ->assertDispatched('rg-admin-toast', message: 'A persona’s account can’t be edited here, so nothing was saved.', tone: 'error');

    expect($persona->fresh())
        ->name->toBe('Kept Name')
        ->email->toBe($email)
        ->role->toBe(UserRole::Persona)
        ->trust_level->toBe(10)
        ->password->toBeNull();
});

it('turns a persona into nobody else, even past the role confirmation', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create();

    Livewire::test(UserEditor::class, ['userId' => $persona->id])
        ->set('role', 'user')
        ->call('save')
        ->assertSet('confirmingRole', true)
        ->assertSeeHtml('Persona → User')
        ->call('save')
        ->assertSet('confirmingRole', false)
        ->assertDispatched('rg-admin-toast', message: 'A persona’s account can’t be edited here, so nothing was saved.', tone: 'error');

    expect($persona->fresh()->role)->toBe(UserRole::Persona);
});

it('saves nothing to an account made a persona since its drawer was drawn', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['name' => 'Kept Name']);
    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);

    User::query()->whereKey($person->id)->update(['role' => UserRole::Persona->value]);

    $page->set('name', 'Forged Name')
        ->call('save')
        ->assertDispatched('rg-admin-toast', message: 'A persona’s account can’t be edited here, so nothing was saved.', tone: 'error');

    expect($person->fresh())->name->toBe('Kept Name')->role->toBe(UserRole::Persona);
});

it('neither offers nor accepts the persona role in anyone else\'s drawer', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create();

    $page = Livewire::test(UserEditor::class, ['userId' => $person->id]);

    expect($page->viewData('roles'))->toBe(['user' => 'User', 'moderator' => 'Moderator', 'admin' => 'Admin']);

    $page->set('role', 'persona')
        ->call('save')
        ->assertHasErrors(['role'])
        ->assertSet('confirmingRole', false);

    expect($person->fresh()->role)->toBe(UserRole::User);
});

it('names any saved role in the role confirmation, one the drawer does not offer included', function () {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create(['username' => 'chef_persona']);
    $page = Livewire::test(UserEditor::class, ['userId' => $persona->id]);

    // Turned back into a member elsewhere while the page stayed open.
    User::query()->whereKey($persona->id)->update(['role' => UserRole::User->value]);

    $page->set('role', 'moderator')
        ->call('save')
        ->assertSet('confirmingRole', true)
        ->assertSeeHtml('Change role to Moderator?')
        ->assertSeeHtml('Persona → Moderator');
});

it('moderates a persona like a member', function (string $kind, UserStatus $status) {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create();

    $page = Livewire::test(UserEditor::class, ['userId' => $persona->id])
        ->call('askToSanction', $kind, $persona->id)
        ->call('confirmSanction');

    expect($persona->fresh()->status)->toBe($status);

    $page->call('restoreAccess', $persona->id);

    expect($persona->fresh())->status->toBe(UserStatus::Active)->role->toBe(UserRole::Persona);
})->with([
    'limit' => ['limit', UserStatus::Limited],
    'shadowban' => ['shadowban', UserStatus::Shadowbanned],
    'ban' => ['ban', UserStatus::Banned],
]);

it('never offers to mark a persona trusted', function (int $trustLevel) {
    $this->actingAs(User::factory()->admin()->create());
    $persona = User::factory()->persona()->create(['trust_level' => $trustLevel]);

    $page = Livewire::test(UserEditor::class, ['userId' => $persona->id]);

    expect($page->viewData('options')['trust']['available'])->toBeFalse();

    $page->call('askToSanction', 'trust', $persona->id)->assertSet('sanction', null);

    expect($persona->fresh()->trust_level)->toBe($trustLevel);
})->with(['not trusted' => 0, 'trusted' => 10]);

it('keeps a trust choice not yet saved when the drawer is drawn again', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['trust_level' => 10]);

    $editor = Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->assertSet('trust', 'trusted')
        ->set('trust', 'reviewed')
        ->dispatch('user-changed')
        // A redraw over the draft would have put 'trusted' back.
        ->assertSet('trust', 'reviewed')
        ->assertSet('saved.trust', 'trusted');

    // Its trust taken away meanwhile: drawn again, the drawer takes that as saved.
    User::query()->whereKey($person->id)->update(['trust_level' => 0]);

    $editor->dispatch('user-changed')
        ->assertSet('saved.trust', 'reviewed')
        ->assertSet('trust', 'reviewed');
});

it('shows the person trusted in the drawer once marked trusted from it, with nothing left to save', function () {
    $this->actingAs(User::factory()->admin()->create());
    $person = User::factory()->create(['trust_level' => 0]);

    Livewire::test(UserEditor::class, ['userId' => $person->id])
        ->assertSet('trust', 'reviewed')
        ->call('askToSanction', 'trust', $person->id)
        ->call('confirmSanction')
        ->assertSet('trust', 'trusted')
        ->assertSet('saved.trust', 'trusted');
});

it('shows when each person joined to the minute, and whose address nobody confirmed', function () {
    $this->travelTo(now()->setDate(2026, 11, 12)->setTime(15, 31));
    $this->actingAs(User::factory()->admin()->create());
    $confirmed = User::factory()->create(['created_at' => now()->subDays(3)]);
    $unconfirmed = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
    $persona = User::factory()->persona()->create();

    $page = Livewire::test(ListUsers::class);

    expect(listedRow($page, $confirmed)['meta'])->toEndWith(' · joined 09 Nov 2026, 15:31')
        ->and(listedRow($page, $confirmed)['unconfirmed'])->toBeFalse()
        ->and(listedRow($page, $unconfirmed)['unconfirmed'])->toBeTrue()
        ->and(listedRow($page, $persona)['unconfirmed'])->toBeFalse();
});

it('tells in a person\'s editor when they confirmed their address, or when they are removed for not doing it', function () {
    $this->travelTo(now()->setDate(2026, 11, 12)->setTime(15, 31));
    $this->actingAs(User::factory()->admin()->create());
    $confirmed = User::factory()->create(['email_verified_at' => now()->subDay()]);
    $unconfirmed = User::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
    $overdue = User::factory()->unverified()->create(['created_at' => now()->subDays(8)]);

    Livewire::test(UserEditor::class, ['userId' => $confirmed->id])
        ->assertSeeText('Confirmed 11 Nov 2026, 15:31');
    Livewire::test(UserEditor::class, ['userId' => $unconfirmed->id])
        ->assertSeeText('09 Nov 2026, 15:31')
        ->assertSeeText('Not confirmed · removed 16 Nov 2026, 15:31 unless confirmed');
    Livewire::test(UserEditor::class, ['userId' => $overdue->id])
        ->assertSeeText('Not confirmed · removed at the next cleanup');
});
