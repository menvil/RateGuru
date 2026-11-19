<?php

use App\Models\User;
use App\Models\UserActivityDay;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The days members did something on the site, and the moment a new account
 * was activated, alike on every engine: one row per member and day, read by
 * day, never deleted from under the member it names; the migrations undo
 * what they did and make it again.
 */

/** @return list<string> the names of a table's indexes */
function indexNamesOf(string $table): array
{
    return array_column(Schema::getIndexes($table), 'name');
}

it('keeps one row per member and day, read by day, with the member it names kept', function () {
    $member = User::factory()->create();
    UserActivityDay::factory()->create(['user_id' => $member->id, 'day' => '2026-11-18']);

    $indexes = collect(Schema::getIndexes('user_activity_days'))->keyBy('name');

    expect($indexes->get('user_activity_days_user_day_unique'))->columns->toBe(['user_id', 'day'])->unique->toBeTrue()
        ->and($indexes->get('user_activity_days_day_index'))->columns->toBe(['day'])
        ->and(Schema::hasColumns('user_activity_days', ['id', 'user_id', 'day', 'day_number', 'voted', 'commented', 'posted', 'played', 'first_at']))->toBeTrue()
        ->and(fn () => DB::transaction(fn () => UserActivityDay::factory()->create(['user_id' => $member->id, 'day' => '2026-11-18'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('users')->where('id', $member->id)->delete()))->toThrow(QueryException::class);

    expect(UserActivityDay::query()->where('user_id', $member->id)->count())->toBe(1);
});

it('gives attributions and visits the moment of activation, and reads attributions by when they signed up', function () {
    expect(Schema::hasColumn('account_attributions', 'activated_at'))->toBeTrue()
        ->and(Schema::hasColumn('traffic_visits', 'activated_at'))->toBeTrue()
        ->and(collect(Schema::getIndexes('account_attributions'))->firstWhere('name', 'account_attributions_signed_up_index'))->columns->toBe(['signed_up_at']);
});

it('undoes what the migrations did and makes it again', function () {
    $days = require database_path('migrations/2026_11_18_100000_create_user_activity_days_table.php');
    $activation = require database_path('migrations/2026_11_18_100100_add_activated_at_to_account_attributions_and_traffic_visits.php');

    $activation->down();
    $days->down();

    expect(Schema::hasTable('user_activity_days'))->toBeFalse()
        ->and(Schema::hasColumn('account_attributions', 'activated_at'))->toBeFalse()
        ->and(Schema::hasColumn('traffic_visits', 'activated_at'))->toBeFalse()
        ->and(indexNamesOf('account_attributions'))->not->toContain('account_attributions_signed_up_index')
        ->and(Schema::hasTable('account_attributions'))->toBeTrue();

    $days->up();
    $activation->up();

    expect(indexNamesOf('user_activity_days'))->toContain('user_activity_days_user_day_unique', 'user_activity_days_day_index')
        ->and(indexNamesOf('account_attributions'))->toContain('account_attributions_signed_up_index')
        ->and(Schema::hasColumn('account_attributions', 'activated_at'))->toBeTrue()
        ->and(Schema::hasColumn('traffic_visits', 'activated_at'))->toBeTrue();
});
