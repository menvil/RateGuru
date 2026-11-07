<?php

use App\Actions\Auth\AuthenticateUserAction;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Event;
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

test('users can authenticate whatever case they type their email in', function () {
    User::factory()->create(['email' => 'ada@example.com']);

    $this->post('/login', [
        'email' => '  Ada@Example.COM ',
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
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
    expect(RateLimiter::attempts(AuthenticateUserAction::throttleKeyFor($persona->email, '127.0.0.1')))->toBe(1);
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

test('a sign-in attempt is counted before the password is checked, so parallel attempts share the limit', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $key = AuthenticateUserAction::throttleKeyFor('ada@example.com', '127.0.0.1');
    $countedWhileChecking = null;

    Event::listen(Attempting::class, function () use ($key, &$countedWhileChecking): void {
        $countedWhileChecking = RateLimiter::attempts($key);
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

    expect($countedWhileChecking)->toBe(1)
        ->and(RateLimiter::attempts($key))->toBe(1);
});

test('a successful sign-in clears the attempts it counted', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $key = AuthenticateUserAction::throttleKeyFor('ada@example.com', '127.0.0.1');

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    expect(RateLimiter::attempts($key))->toBe(0);
});

test('counts failed sign-ins under a key that holds neither the address nor the client IP', function () {
    $user = User::factory()->create(['email' => 'probe-person@example.test']);
    $keys = [];
    Event::listen([KeyWritten::class, CacheHit::class, CacheMissed::class], function (object $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertSessionHasErrors('email');

    expect($keys)->not->toBe([])
        ->and(implode(' ', $keys))->not->toContain('probe-person')->not->toContain('127.0.0.1');
});

test('keys the failed sign-in count with the app key, so it cannot be found by hashing a guessed address and IP', function () {
    $plain = 'login:'.hash('sha256', 'ada@example.com|127.0.0.1');

    $key = AuthenticateUserAction::throttleKeyFor('ada@example.com', '127.0.0.1');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

    expect($key)->not->toBe($plain)
        ->and(AuthenticateUserAction::throttleKeyFor('ada@example.com', '127.0.0.1'))->not->toBe($key);
});
