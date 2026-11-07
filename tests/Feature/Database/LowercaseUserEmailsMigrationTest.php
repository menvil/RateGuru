<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Sign-in compares the typed address lowercased, so the migration lowers any
 * stored address that is not — and never merges or overwrites two accounts
 * that differ only in case.
 */

function lowercaseUserEmailsMigration(): object
{
    return require database_path('migrations/2026_11_07_100000_lowercase_user_emails.php');
}

it('lowercases a stored address with capitals', function () {
    $admin = User::factory()->create();
    DB::table('users')->where('id', $admin->id)->update(['email' => 'Admin@Example.TEST']);

    lowercaseUserEmailsMigration()->up();

    expect(DB::table('users')->where('id', $admin->id)->value('email'))->toBe('admin@example.test');
});

it('leaves an address alone when another account already holds its lowercase form', function () {
    $lower = User::factory()->create(['email' => 'ada@example.com']);
    $upper = User::factory()->create();
    DB::table('users')->where('id', $upper->id)->update(['email' => 'Ada@Example.com']);

    lowercaseUserEmailsMigration()->up();

    expect(DB::table('users')->where('id', $lower->id)->value('email'))->toBe('ada@example.com')
        ->and(DB::table('users')->where('id', $upper->id)->value('email'))->toBe('Ada@Example.com');
})->skip(fn (): bool => in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'A case-insensitive unique index cannot hold two addresses that differ only in case.');
