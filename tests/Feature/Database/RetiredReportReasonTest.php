<?php

use App\Enums\ReportReason;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * `ReportReason::NotFood` became `WrongCategory` when the retired food-specific
 * vocabulary was removed, and nothing migrated the rows that already held the old
 * value. `Report::reason` casts the column to the enum, so such a row throws the
 * moment anything reads it — the moderation queue, an export, anywhere.
 */
function retiredReasonReportId(): int
{
    // Raw SQL on purpose: the value cannot be expressed through the enum any
    // more, which is exactly the state the migration exists to repair.
    return DB::table('reports')->insertGetId([
        'reporter_id' => User::factory()->create()->id,
        'target_type' => Post::class,
        'target_id' => Post::factory()->create()->id,
        'reason' => 'not_food',
        'status' => 'open',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('cannot read a report still holding the retired reason', function () {
    // The failure the migration prevents, demonstrated rather than asserted
    // about: this is what a surviving row does to any code path that reads it.
    $id = retiredReasonReportId();

    expect(fn () => Report::query()->findOrFail($id)->reason)->toThrow(ValueError::class);
});

it('maps a persisted retired reason onto wrong_category', function () {
    $id = retiredReasonReportId();

    // The migration's own statement, applied as the migration applies it.
    DB::table('reports')->where('reason', 'not_food')->update(['reason' => 'wrong_category']);

    expect(Report::query()->findOrFail($id)->reason)->toBe(ReportReason::WrongCategory);
});

it('offers no way to write the retired reason again', function () {
    // Which is what makes the migration a one-way repair rather than a sweep
    // that has to keep running.
    expect(collect(ReportReason::cases())->pluck('value')->all())->not->toContain('not_food');
    expect(ReportReason::tryFrom('not_food'))->toBeNull();
});
