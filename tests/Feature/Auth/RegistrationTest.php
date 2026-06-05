<?php

use App\Actions\Users\GenerateUniqueUsernameAction;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

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
        ->times(RegisteredUserController::MAX_CREATE_ATTEMPTS)
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

test('registration does not retry non-username query exceptions', function () {
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

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', [
        'name' => 'Test User',
        'email' => 'racing@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(UniqueConstraintViolationException::class);
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
        'name' => 'Unable to generate a unique username.',
    ]);
});
