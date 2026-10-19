<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who prepares publication plans: an active administrator, and nobody else —
 * a capability of its own, not something managing Personas implies.
 */
it('lets only an active administrator manage publication plans', function (Closure $user, bool $allowed) {
    expect(Gate::forUser($user())->allows('manage-studio-plans'))->toBe($allowed);
})->with([
    'an active administrator' => [fn () => User::factory()->admin()->create(), true],
    'a limited administrator' => [fn () => User::factory()->admin()->limited()->create(), false],
    'a banned administrator' => [fn () => User::factory()->admin()->banned()->create(), false],
    'a shadowbanned administrator' => [fn () => User::factory()->admin()->shadowbanned()->create(), false],
    'a deleted administrator' => [fn () => User::factory()->admin()->tombstoned()->create(), false],
    'a moderator' => [fn () => User::factory()->moderator()->create(), false],
    'a member' => [fn () => User::factory()->create(), false],
    'a persona' => [fn () => User::factory()->persona()->create(), false],
]);

it('lets no guest manage publication plans', function () {
    expect(Gate::allows('manage-studio-plans'))->toBeFalse();
});

it('is a gate of its own, not the one that manages personas', function () {
    $admin = User::factory()->admin()->create();

    // Managing Personas taken away alone leaves publication plans as they were.
    Gate::define('manage-personas', fn (): bool => false);

    expect(Gate::forUser($admin)->allows('manage-personas'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('manage-studio-plans'))->toBeTrue();
});
