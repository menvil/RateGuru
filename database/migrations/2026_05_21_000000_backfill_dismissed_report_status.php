<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * ReportStatus::Dismissed ('dismissed') was renamed to
     * ReportStatus::Ignored ('ignored'). No code path in this codebase ever
     * wrote 'dismissed', so this is a no-op on a clean database — but it
     * guards any already-deployed environment where a stray row would
     * otherwise break enum casting on Report::status.
     */
    public function up(): void
    {
        DB::table('reports')
            ->where('status', 'dismissed')
            ->update(['status' => 'ignored']);
    }

    public function down(): void
    {
        // Irreversible: 'dismissed' is no longer a valid status. The data was
        // already canonicalised to 'ignored'; there is nothing safe to revert.
    }
};
