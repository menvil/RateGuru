<?php

use App\Actions\Auth\AuthenticateUserAction;
use App\Actions\Auth\ConfirmPasswordAction;
use App\Actions\Auth\ResetPasswordAction;
use App\Actions\Auth\UpdatePasswordAction;
use App\Actions\Users\UpdateUserAccountAction;
use App\Models\User;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Password;

/*
 * A failure reported from under a flow that takes a plain password carries
 * the arguments of the calls it happened in. The password is never one.
 */

function failTheDatabaseOn(string $event): void
{
    Event::listen($event, function (): void {
        throw new QueryException((string) config('database.default'), 'users', [], new PDOException('server has gone away'));
    });
}

// Each builds the call to run, so no frame of the test itself holds the password.
dataset('flows writing a new password', [
    'a reset from the emailed link' => [fn (User $admin, User $user, string $password) => fn () => app(ResetPasswordAction::class)->execute([
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => $password,
    ])],
    'a change on the profile' => [fn (User $admin, User $user, string $password) => fn () => app(UpdatePasswordAction::class)->execute($user, $password)],
    'an administrator editing the account' => [fn (User $admin, User $user, string $password) => fn () => app(UpdateUserAccountAction::class)->handle($admin, $user, [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'role' => $user->role,
        'trusted' => null,
        'password' => $password,
    ], [
        'name' => $user->name,
        'username' => $user->username,
        'email' => $user->email,
        'role' => $user->role->value,
    ])],
]);

it('keeps the password out of a failed sign-in', function () {
    $user = User::factory()->create();
    failTheDatabaseOn(Attempting::class);

    $values = reportedCallArguments(fn () => app(AuthenticateUserAction::class)->execute(
        ['email' => $user->email, 'password' => 'a-long-password'],
        request(),
    ));

    expect($values)->not->toContain('a-long-password');
});

it('keeps the password out of a failed confirmation', function () {
    $user = User::factory()->create();
    failTheDatabaseOn('eloquent.retrieved: '.User::class);

    $values = reportedCallArguments(fn () => app(ConfirmPasswordAction::class)->execute($user, 'a-long-password'));

    expect($values)->not->toContain('a-long-password');
});

it('keeps a new password out of a failed save', function (Closure $write) {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    failTheDatabaseOn('eloquent.saving: '.User::class);

    $values = reportedCallArguments($write($admin, $user, 'a-long-password'));

    expect($values)->not->toContain('a-long-password');
})->with('flows writing a new password');

it('keeps a new password out of a failed hash', function (Closure $write) {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $password = "long-enough\0password";

    $values = reportedCallArguments($write($admin, $user, $password));

    expect($values)->not->toContain($password);
})->with('flows writing a new password');
