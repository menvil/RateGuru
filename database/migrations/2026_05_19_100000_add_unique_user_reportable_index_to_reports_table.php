<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing data may already contain duplicate reports (the
        // application-level guard does not retroactively clean rows). Keep the
        // earliest report per (reporter_id, target_type, target_id) so the
        // unique index can be created without erroring.
        $keepIds = DB::table('reports')
            ->selectRaw('MIN(id) as id')
            ->groupBy('reporter_id', 'target_type', 'target_id')
            ->pluck('id');

        if ($keepIds->isNotEmpty()) {
            DB::table('reports')->whereNotIn('id', $keepIds)->delete();
        }

        Schema::table('reports', function (Blueprint $table) {
            $table->unique(
                ['reporter_id', 'target_type', 'target_id'],
                'reports_reporter_target_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique('reports_reporter_target_unique');
        });
    }
};
