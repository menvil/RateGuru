<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ReportReason::NotFood` was renamed to `WrongCategory` when the retired
 * food-specific vocabulary was removed, and the persisted values were left
 * behind. `Report::reason` casts the column to the enum, so any row still
 * holding `not_food` throws a ValueError the moment it is read — on the
 * moderation queue, in an export, anywhere.
 *
 * The rename was a judgement about wording, not about meaning: a report filed as
 * "not food" was always "wrong category" for this project. So the rows are
 * mapped rather than deleted, and the reports keep their reason.
 *
 * Idempotent, and safe on an installation that never held the old value: the
 * WHERE matches nothing and the migration is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('reports')
            ->where('reason', 'not_food')
            ->update(['reason' => 'wrong_category']);
    }

    /**
     * Deliberately irreversible. `not_food` is not a value the application can
     * represent any more, so writing it back would reintroduce rows that throw
     * on access — a rollback that breaks what it restores.
     */
    public function down(): void {}
};
