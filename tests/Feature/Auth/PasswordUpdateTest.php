<?php

use App\Models\User;
use App\Support\Auth\SessionGeneration;
use Illuminate\Support\Facades\Hash;

test('password can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('updatePassword', 'current_password')
        ->assertRedirect('/profile');
});

test('changing the password ends every other session of the account, and keeps this one', function () {
    $user = User::factory()->create(['session_generation' => 'before-the-change', 'remember_token' => 'old-remember-token']);

    $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'before-the-change'])
        ->from('/profile')
        ->put('/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->session_generation)->not->toBe('before-the-change')
        ->and($user->remember_token)->not->toBe('old-remember-token');

    // This browser carries on.
    $this->get(route('feed'))->assertOk();
    $this->assertAuthenticatedAs($user);

    // A session elsewhere, still on the old generation, is signed out.
    $this->actingAs($user)
        ->withSession([SessionGeneration::SESSION_KEY => 'before-the-change'])
        ->get(route('feed'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});

test('the password form stops checking the current password after six guesses a minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $guess) {
        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);
    }

    $this->actingAs($user)->from('/profile')->put('/password', [
        'current_password' => 'wrong-password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertStatus(429);
});
