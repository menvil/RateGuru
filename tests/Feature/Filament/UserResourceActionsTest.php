<?php

use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ModerationLog;
use App\Models\User;
use Livewire\Livewire;

/**
 * The moderation actions on a person, from the Users list's row menu and
 * from their page: Limit, Shadowban and Ban after a confirmation, with Undo
 * from an active account; Restore access at once, with Undo; Mark trusted
 * after a confirmation. Who may do what, and why not, as UserPolicy has it.
 */
beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->actingAs($this->admin);
});

it('applies a sanction after asking, with the reason, and Undo restores access', function (string $kind, UserStatus $status, string $word) {
    $person = User::factory()->create(['username' => 'grumpy_max']);

    $page = Livewire::test(ListUsers::class)
        ->call('askToSanction', $kind, $person->id)
        ->assertSet('sanction', ['kind' => $kind, 'id' => $person->id])
        ->set('reason', 'Repeated spam')
        ->call('confirmSanction')
        ->assertSet('sanction', null)
        ->assertDispatched('rg-admin-toast', message: 'grumpy_max is now '.$word, action: ['label' => 'Undo', 'event' => 'restore-user-access', 'params' => ['id' => $person->id]]);

    expect($person->fresh()->status)->toBe($status)
        ->and(ModerationLog::query()->latest('id')->first()->reason)->toBe('Repeated spam');

    $page->dispatch('restore-user-access', id: $person->id)
        ->assertDispatched('rg-admin-toast', message: 'Access restored for grumpy_max');

    expect($person->fresh()->status)->toBe(UserStatus::Active);
})->with([
    'ban' => ['ban', UserStatus::Banned, 'banned'],
    'limit' => ['limit', UserStatus::Limited, 'limited'],
    'shadowban' => ['shadowban', UserStatus::Shadowbanned, 'shadowbanned'],
]);

it('says what each sanction really does: no participation, never a locked account', function () {
    $person = User::factory()->create();

    Livewire::test(ListUsers::class)
        ->call('askToSanction', 'ban', $person->id)
        ->assertSeeHtml('They can still sign in, browse and manage their account.')
        ->assertSeeHtml('No new posts, comments, votes, reports or follows.')
        ->assertDontSeeHtml('signed out');
});

it('offers no Undo for a sanction on someone already restricted', function () {
    $person = User::factory()->limited()->create(['username' => 'kotofey']);

    Livewire::test(ListUsers::class)
        ->call('askToSanction', 'ban', $person->id)
        ->call('confirmSanction')
        ->assertDispatched('rg-admin-toast', message: 'kotofey is now banned', action: null);
});

it('restores access at once, and Undo applies the sanction again', function () {
    $person = User::factory()->banned()->create(['username' => 'spam_pawz']);

    $page = Livewire::test(ListUsers::class)
        ->call('restoreAccess', $person->id)
        ->assertDispatched('rg-admin-toast', message: 'Access restored for spam_pawz', action: ['label' => 'Undo', 'event' => 'sanction-user-again', 'params' => ['id' => $person->id, 'kind' => 'ban']]);

    expect($person->fresh()->status)->toBe(UserStatus::Active);

    $page->dispatch('sanction-user-again', id: $person->id, kind: 'ban')
        ->assertDispatched('rg-admin-toast', message: 'spam_pawz is banned again');

    expect($person->fresh()->status)->toBe(UserStatus::Banned);
});

it('marks someone trusted after asking, without a reason to give', function () {
    $person = User::factory()->create(['username' => 'dasha_lapki', 'trust_level' => 0]);

    Livewire::test(ListUsers::class)
        ->call('askToSanction', 'trust', $person->id)
        ->assertSeeHtml('Mark dasha_lapki as trusted?')
        ->assertDontSeeHtml('rg-admin-moderation-reason')
        ->call('confirmSanction')
        ->assertDispatched('rg-admin-toast', message: 'dasha_lapki is now trusted', action: null);

    expect($person->fresh()->trust_level)->toBe(10);
});

it('says why an action is unavailable, and opens no confirmation for it', function (string $case, string $kind, string $hint) {
    $person = match ($case) {
        'an administrator' => User::factory()->admin()->create(),
        'themselves' => $this->admin,
        'a trusted member' => User::factory()->create(['trust_level' => 10]),
        default => User::factory()->limited()->create(['trust_level' => 0]),
    };

    $page = Livewire::test(ListUsers::class);

    expect(listedRow($page, $person)['options'][$kind])->toMatchArray(['available' => false, 'hint' => $hint]);

    $page->call('askToSanction', $kind, $person->id)->assertSet('sanction', null);
})->with([
    'an administrator' => ['an administrator', 'ban', 'Administrators can’t be sanctioned'],
    'themselves' => ['themselves', 'limit', 'This is your account'],
    'a trusted member' => ['a trusted member', 'trust', 'Already trusted'],
    'a restricted member' => ['a restricted member', 'trust', 'Restore access first'],
]);

it('lets a moderator see the list but take no moderation action', function () {
    $this->actingAs(User::factory()->moderator()->create());
    $person = User::factory()->create();

    $page = Livewire::test(ListUsers::class);
    $row = listedRow($page, $person);

    expect($row['editUrl'])->toBeNull()
        ->and($row['options']['ban'])->toMatchArray(['available' => false, 'hint' => 'Administrators only']);

    $page->call('askToSanction', 'ban', $person->id)->assertSet('sanction', null);

    expect($person->fresh()->status)->toBe(UserStatus::Active);
});

it('moderates from the person\'s page too', function () {
    $person = User::factory()->create();

    Livewire::test(EditUser::class, ['record' => $person->id])
        ->call('askToSanction', 'shadowban', $person->id)
        ->call('confirmSanction');

    expect($person->fresh()->status)->toBe(UserStatus::Shadowbanned);
});
