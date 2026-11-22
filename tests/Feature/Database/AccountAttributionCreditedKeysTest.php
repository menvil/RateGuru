<?php

use App\Enums\TrafficDevice;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/*
 * What Retention reads of the visit and attempt an account is credited to,
 * kept on its attribution alike on every engine: three nullable columns,
 * filled by the migration for the attributions before them from the visits
 * and attempts they name — none for one credited to nothing or to a visit
 * already purged, as Retention read it — and taken off again by down().
 */

/**
 * An attribution as the release before the copies wrote it: credited to the
 * visit and attempt given, or none. Filled by force: a guarded fill would
 * remember the columns of the table without the copies for every later test.
 */
function attributionBeforeCreditedKeys(?TrafficVisit $visit = null, ?GameAttempt $attempt = null): AccountAttribution
{
    return AccountAttribution::query()->forceCreate([
        'user_id' => User::factory()->create()->id,
        'signed_up_at' => now(),
        'confirmed_at' => now(),
        'credited_visit_id' => $visit?->id,
        'credited_attempt_id' => $attempt?->id,
    ]);
}

it('keeps the source key, device and game of what an account is credited to, nullable', function () {
    $attribution = AccountAttribution::factory()->create();

    expect(Schema::hasColumns('account_attributions', ['credited_source_key', 'credited_device', 'credited_game_id']))->toBeTrue()
        ->and($attribution->fresh())
        ->credited_source_key->toBeNull()
        ->credited_device->toBeNull()
        ->credited_game_id->toBeNull();
});

it('fills them for the attributions before it from the visits and attempts they name', function () {
    accountCreditedKeysMigration()->down();

    $paid = TrafficVisit::factory()->create(['source_key' => 'paid:5', 'device' => 'mobile']);
    $unpaid = TrafficVisit::factory()->unpaid()->create(['source_key' => 'reddit:organic', 'device' => 'desktop']);
    $unkeyed = TrafficVisit::factory()->unpaid()->create(['source_key' => null, 'device' => 'desktop']);
    $purged = TrafficVisit::factory()->create(['source_key' => 'paid:6', 'device' => 'mobile']);
    $attempt = GameAttempt::factory()->create();

    $both = attributionBeforeCreditedKeys($paid, $attempt);
    $visitOnly = attributionBeforeCreditedKeys($unpaid);
    $noKey = attributionBeforeCreditedKeys($unkeyed);
    $attemptOnly = attributionBeforeCreditedKeys(attempt: $attempt);
    $gone = attributionBeforeCreditedKeys($purged);
    $nothing = attributionBeforeCreditedKeys();
    $purged->delete();

    accountCreditedKeysMigration()->up();

    $keys = fn (AccountAttribution $attribution): array => [
        $attribution->fresh()->credited_source_key,
        $attribution->fresh()->credited_device,
        $attribution->fresh()->credited_game_id,
    ];

    expect($keys($both))->toBe(['paid:5', TrafficDevice::Mobile, $attempt->game_id])
        ->and($keys($visitOnly))->toBe(['reddit:organic', TrafficDevice::Desktop, null])
        ->and($keys($noKey))->toBe([null, TrafficDevice::Desktop, null])
        ->and($keys($attemptOnly))->toBe([null, null, $attempt->game_id])
        ->and($keys($gone))->toBe([null, null, null])
        ->and($gone->fresh()->credited_visit_id)->toBeNull()
        ->and($keys($nothing))->toBe([null, null, null]);
});

it('undoes what it did and does it again', function () {
    $visit = TrafficVisit::factory()->create(['source_key' => 'paid:8', 'device' => 'desktop']);
    $attribution = AccountAttribution::factory()->create(['credited_visit_id' => $visit->id]);

    accountCreditedKeysMigration()->down();

    expect(Schema::hasColumn('account_attributions', 'credited_source_key'))->toBeFalse()
        ->and(Schema::hasColumn('account_attributions', 'credited_device'))->toBeFalse()
        ->and(Schema::hasColumn('account_attributions', 'credited_game_id'))->toBeFalse()
        ->and(AccountAttribution::query()->whereKey($attribution->id)->value('credited_visit_id'))->toBe($visit->id);

    accountCreditedKeysMigration()->up();

    expect($attribution->fresh())
        ->credited_source_key->toBe('paid:8')
        ->credited_device->toBe(TrafficDevice::Desktop);
});
