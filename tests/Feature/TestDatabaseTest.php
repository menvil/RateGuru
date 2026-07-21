<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('uses an isolated supported test database', function () {
    expect(DB::connection()->getDriverName())
        ->toBeIn(['pgsql', 'sqlite', 'mariadb', 'mysql']);

    if (DB::connection()->getDriverName() === 'sqlite') {
        expect(DB::connection()->getDatabaseName())->toBe(':memory:');
    }

    expect(DB::connection()->getSchemaBuilder()->hasTable('users'))->toBeTrue()
        ->and(DB::connection()->getSchemaBuilder()->hasTable('migrations'))->toBeTrue();

    User::factory()->create();

    expect(User::count())->toBe(1);
});
