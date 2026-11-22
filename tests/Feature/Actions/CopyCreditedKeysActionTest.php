<?php

use App\Actions\Traffic\CopyCreditedKeysAction;
use App\Enums\TrafficDevice;
use App\Models\AccountAttribution;
use App\Models\GameAttempt;
use App\Models\TrafficVisit;

/*
 * What Retention reads of the visit and attempt an account is credited to,
 * copied onto the attributions written without it — by the release before
 * the copies, while a deploy runs between migrating and switching over —
 * and onto no other: one that has them, one credited to nothing and one
 * whose visit was purged since keep theirs. analytics:refresh-visits does it
 * every run.
 */

it('copies what Retention reads onto the attributions written without it, and leaves every other as it is', function () {
    $visit = TrafficVisit::factory()->create(['source_key' => 'paid:2', 'device' => 'mobile']);
    $attempt = GameAttempt::factory()->create();
    $without = AccountAttribution::factory()->create(['credited_visit_id' => $visit->id, 'credited_attempt_id' => $attempt->id, 'credited_source_key' => null, 'credited_device' => null, 'credited_game_id' => null]);
    $withoutGame = AccountAttribution::factory()->create(['credited_attempt_id' => $attempt->id, 'credited_game_id' => null]);
    $copied = AccountAttribution::factory()->create(['credited_visit_id' => $visit->id]);
    // Its visit purged since: the pointer let go, the copies kept.
    $purged = AccountAttribution::factory()->create(['credited_source_key' => 'reddit:organic', 'credited_device' => 'desktop']);
    $nothing = AccountAttribution::factory()->create();

    expect(app(CopyCreditedKeysAction::class)->handle())->toBe(2)
        ->and($without->fresh())
        ->credited_source_key->toBe('paid:2')
        ->credited_device->toBe(TrafficDevice::Mobile)
        ->credited_game_id->toBe($attempt->game_id)
        ->and($withoutGame->fresh())
        ->credited_game_id->toBe($attempt->game_id)
        ->credited_source_key->toBeNull()
        ->credited_device->toBeNull()
        ->and($copied->fresh())
        ->credited_source_key->toBe('paid:2')
        ->credited_device->toBe(TrafficDevice::Mobile)
        ->and($purged->fresh())
        ->credited_source_key->toBe('reddit:organic')
        ->credited_device->toBe(TrafficDevice::Desktop)
        ->and($nothing->fresh())
        ->credited_source_key->toBeNull()
        ->credited_device->toBeNull()
        ->credited_game_id->toBeNull()
        ->and(app(CopyCreditedKeysAction::class)->handle())->toBe(0);
});

it('is run by analytics:refresh-visits', function () {
    $visit = TrafficVisit::factory()->create(['source_key' => 'google:organic', 'device' => 'desktop']);
    $without = AccountAttribution::factory()->create(['credited_visit_id' => $visit->id, 'credited_source_key' => null, 'credited_device' => null]);

    $this->artisan('analytics:refresh-visits')->assertSuccessful();

    expect($without->fresh())
        ->credited_source_key->toBe('google:organic')
        ->credited_device->toBe(TrafficDevice::Desktop);
});
