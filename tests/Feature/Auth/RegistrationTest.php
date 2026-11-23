<?php

use App\Actions\Auth\RegisterUserAction;
use App\Actions\Auth\ScreenRegistrationAction;
use App\Actions\Moderation\MarkUserTrustedAction;
use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Auth\AuthSurfaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'username' => 'test_user',
    ]);
});

test('registration never takes a role, a status, a trust level or a confirmed address from the form', function () {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => UserRole::Persona->value,
        'status' => UserStatus::Banned->value,
        'trust_level' => 99,
        'email_verified_at' => now()->toDateTimeString(),
    ]);

    $user = User::query()->where('email', 'test@example.com')->sole();

    expect($user->role)->toBe(UserRole::User)
        ->and($user->status)->toBe(UserStatus::Active)
        ->and($user->trust_level)->toBe(MarkUserTrustedAction::TRUSTED_LEVEL)
        ->and($user->hasVerifiedEmail())->toBeFalse();
    $this->assertAuthenticatedAs($user);
});

test('registration retries username unique constraint violations', function () {
    User::factory()->create(['username' => 'taken_username']);

    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')
        ->twice()
        ->andReturn('taken_username', 'available_username');
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'retry@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertDatabaseHas('users', [
        'email' => 'retry@example.com',
        'username' => 'available_username',
    ]);
});

test('registration stops retrying username collisions after the maximum attempts', function () {
    User::factory()->create(['username' => 'taken_username']);

    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')
        ->times(RegisterUserAction::MAX_CREATE_ATTEMPTS)
        ->andReturn('taken_username');
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'exhausted@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors([
        'name' => 'Unable to create a unique username. Please try a different name.',
    ]);
});

test('registration does not retry an address taken meanwhile, and says the address is taken', function () {
    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')
        ->once()
        ->andReturnUsing(function (): string {
            User::factory()->create([
                'username' => 'racing_user',
                'email' => 'racing@example.com',
            ]);

            return 'available_username';
        });
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'racing@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'racing@example.com')->count())->toBe(1);
    $this->assertGuest();
});

test('registration lets a database failure inside the username generator propagate', function () {
    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')
        ->once()
        ->andThrow(new QueryException('pgsql', 'select exists(...)', [], new RuntimeException('connection lost')));
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'name' => 'Test User',
        'email' => 'db-failure@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(QueryException::class, 'connection lost');

    $this->assertGuest();
});

test('registration converts username generation exhaustion into a validation error', function () {
    $action = Mockery::mock(GenerateUniqueUsernameAction::class);
    $action->shouldReceive('handle')
        ->once()
        ->andThrow(new RuntimeException('Unable to generate a unique username.'));
    app()->instance(GenerateUniqueUsernameAction::class, $action);

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'generator-exhausted@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors([
        // The generator's own message is for logs; the reader gets ours.
        'name' => __('auth.username_unavailable'),
    ]);
});

test('limits how many accounts one client can register in a minute', function () {
    foreach (range(1, 6) as $n) {
        $this->post('/register', ['name' => "Person {$n}", 'username' => "person_{$n}", 'email' => "person{$n}@example.test", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
        auth()->logout();
    }

    $this->post('/register', ['name' => 'Person 7', 'username' => 'person_7', 'email' => 'person7@example.test', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
        ->assertTooManyRequests();
});

test('counts registrations on their own, so reset links asked for from the same client do not use them up', function () {
    Notification::fake();

    foreach (range(1, 6) as $request) {
        $this->post('/forgot-password', ['email' => "someone{$request}@example.test"]);
    }

    $this->post('/register', ['name' => 'Ada', 'username' => 'ada', 'email' => 'ada@example.test', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
        ->assertRedirect();

    $this->assertAuthenticated();
});

test('keeps the password out of the call arguments a failed sign-up reports', function () {
    $ignoredArguments = ini_set('zend.exception_ignore_args', '0');
    $failure = null;

    Event::listen('eloquent.creating: '.User::class, function (): void {
        throw new QueryException((string) config('database.default'), 'insert into users', [], new PDOException('server has gone away'));
    });

    try {
        $this->withoutExceptionHandling()->post('/register', [
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);
    } catch (QueryException $caught) {
        $failure = $caught;
    } finally {
        ini_set('zend.exception_ignore_args', (string) $ignoredArguments);
    }

    $arguments = [];

    // The test client's own calls hold the form it was asked to send.
    for ($current = $failure; $current !== null; $current = $current->getPrevious()) {
        $application = array_filter($current->getTrace(), fn (array $frame): bool => ($frame['class'] ?? null) !== TestCase::class);
        $arguments[] = array_column($application, 'args');
    }

    $values = [];
    array_walk_recursive($arguments, function (mixed $value) use (&$values): void {
        $values[] = $value;
    });

    // The address is there, so the frames did carry their arguments.
    expect($failure)->not->toBeNull()
        ->and($values)->toContain('ada@example.test')
        ->and($values)->not->toContain('a-long-password');
});

test('marks the whole sign-up form as sensitive wherever it is passed', function (string $class, string $method) {
    $form = (new ReflectionMethod($class, $method))->getParameters()[0];

    expect($form->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    'creating the account' => [RegisterUserAction::class, 'execute'],
    'screening the sign-up' => [ScreenRegistrationAction::class, 'execute'],
    'reading the surface' => [AuthSurfaceContext::class, 'fromInput'],
]);
