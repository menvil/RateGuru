<?php

use App\Enums\UserStatus;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'username' => 'test_user',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test_user', $user->username);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('changing the email takes the account\'s password, and only the email does', function () {
    $user = User::factory()->create(['email' => 'kept@example.com', 'username' => 'kept_user']);

    // A session left open somewhere cannot move the account to another mailbox.
    foreach ([[], ['current_password' => 'not-the-password']] as $given) {
        $this->actingAs($user)
            ->patch('/profile', ['name' => 'Kept', 'username' => 'kept_user', 'email' => 'taken-over@example.com', ...$given])
            ->assertSessionHasErrors('current_password');

        expect($user->fresh()->email)->toBe('kept@example.com');
    }

    // The name and the username alone need no password.
    $this->actingAs($user)
        ->patch('/profile', ['name' => 'Renamed', 'username' => 'kept_user', 'email' => 'kept@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->name)->toBe('Renamed');
});

test('an account with no password yet changes its email without one', function () {
    $user = User::factory()->create(['email' => 'social@example.com', 'username' => 'social_user', 'password' => null]);

    $this->actingAs($user)
        ->patch('/profile', ['name' => 'Social', 'username' => 'social_user', 'email' => 'moved@example.com'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->toBe('moved@example.com');
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create([
        'username' => 'test_user',
    ]);

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'username' => $user->username,
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

it('requires a canonical lowercase username', function (string $username) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'username' => $username,
            'email' => $user->email,
        ])
        ->assertSessionHasErrors('username');
})->with([
    'uppercase characters' => 'Test_User',
    'hyphens' => 'test-user',
]);

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();

    // Account deletion tombstones the row instead of deleting it: the user
    // is anonymized and permanently disabled, never physically removed.
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBe(UserStatus::Deleted);
    expect($fresh->anonymized_at)->not->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    // The rejected attempt leaves the account fully alive — not merely
    // present, but in its original lifecycle state with no tombstone marks.
    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBe(UserStatus::Active);
    expect($fresh->anonymized_at)->toBeNull();
});

test('a sanctioned account that may not edit its profile is told why, not shown an error page', function () {
    $user = User::factory()->limited()->create(['name' => 'Original', 'username' => 'original_name']);

    $this->actingAs($user)
        ->patch('/profile', [
            'name' => 'Changed',
            'username' => $user->username,
            'email' => $user->email,
        ])
        ->assertRedirect('/profile')
        ->assertSessionHasErrors(['name' => __('ui.account_restriction.limited')]);

    expect($user->fresh()->name)->toBe('Original');
});
