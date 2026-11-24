<?php

use App\Models\TrafficVisit;
use Illuminate\Support\Facades\Schema;

/**
 * The registration steps a visit reaches — the sign-in dialog opened and
 * what opened it, the registration form seen, Google or Facebook started,
 * the email form sent, a sign-up refused and every reason why — each in a
 * column of its own on the visit, empty on a visit that reached none.
 */
const REGISTRATION_STEP_COLUMNS = [
    'auth_opened_at', 'auth_trigger', 'register_form_at', 'social_started_at', 'social_provider',
    'signup_submitted_at', 'signup_refused_at', 'signup_refusals',
];

it('keeps the registration steps a visit reached, none on a visit that reached none', function () {
    $visit = TrafficVisit::factory()->create()->fresh();

    expect(Schema::hasColumns('traffic_visits', REGISTRATION_STEP_COLUMNS))->toBeTrue()
        ->and($visit->auth_opened_at)->toBeNull()
        ->and($visit->auth_trigger)->toBeNull()
        ->and($visit->register_form_at)->toBeNull()
        ->and($visit->social_started_at)->toBeNull()
        ->and($visit->social_provider)->toBeNull()
        ->and($visit->signup_submitted_at)->toBeNull()
        ->and($visit->signup_refused_at)->toBeNull()
        ->and($visit->signup_refusals)->toBe(0);
});

it('undoes what it added and adds it again, the visits staying', function () {
    $visit = TrafficVisit::factory()->create();
    $migration = require database_path('migrations/2026_11_24_120000_add_registration_steps_to_traffic_visits_table.php');

    $migration->down();

    expect(array_filter(REGISTRATION_STEP_COLUMNS, fn (string $column): bool => Schema::hasColumn('traffic_visits', $column)))->toBe([]);

    $migration->up();

    expect(Schema::hasColumns('traffic_visits', REGISTRATION_STEP_COLUMNS))->toBeTrue()
        ->and(TrafficVisit::query()->whereKey($visit->id)->exists())->toBeTrue();
});
