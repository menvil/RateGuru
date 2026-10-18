<?php

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

test('login screen can be rendered', function () {
    $response = $this->get('/login');

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('users can authenticate with the remember checkbox value from the browser', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertAuthenticatedAs($user);
});

test('living sanctioned users can still authenticate', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
})->with(['limited', 'banned', 'shadowbanned']);

test('a deleted tombstone cannot authenticate even with valid credentials', function () {
    // Anonymization normally scrambles credentials; craft a tombstone with
    // a known password to prove the explicit auth boundary fails closed —
    // with the generic failure message, revealing nothing.
    $user = User::factory()->create(['status' => UserStatus::Deleted]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $this->assertGuest();
    $response->assertRedirect('/');
});

/*
 * A Persona never signs in for itself. It is never given a password; this
 * proves the boundary holds when one reaches its row anyway — refused with
 * the same generic failure, the session ended and the attempt counted like
 * any other failed one.
 */
test('a persona cannot authenticate even with a password written straight into its row', function (string $state) {
    $persona = User::factory()->persona()->create(['status' => UserStatus::from($state)]);
    User::query()->whereKey($persona->id)->update(['password' => Hash::make('password')]);

    $response = $this->post('/login', [
        'email' => $persona->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $this->assertGuest();
    $response->assertSessionHasErrors(['email' => trans('auth.failed')]);
    expect(RateLimiter::attempts(Str::transliterate(Str::lower($persona->email).'|127.0.0.1')))->toBe(1);
})->with(['active', 'limited', 'banned', 'shadowbanned']);

test('a persona is locked out after the same number of attempts as anyone', function () {
    $persona = User::factory()->persona()->create();
    User::query()->whereKey($persona->id)->update(['password' => Hash::make('password')]);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', ['email' => $persona->email, 'password' => 'password']);
    }

    $this->post('/login', ['email' => $persona->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect(session('errors')->first('email'))->toStartWith(Str::before(trans('auth.throttle'), ':seconds'));
});
