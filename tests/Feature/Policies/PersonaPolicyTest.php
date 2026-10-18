<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who makes and edits Personas: an active administrator, and nobody else —
 * getting into the admin panel, as a moderator does, is not enough.
 */
it('lets only an active administrator manage personas', function (Closure $user, bool $allowed) {
    expect(Gate::forUser($user())->allows('manage-personas'))->toBe($allowed);
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

it('lets no guest manage personas', function () {
    expect(Gate::allows('manage-personas'))->toBeFalse();
});
