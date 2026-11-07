<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('project_settings')->find(1);
        if (! $row) {
            return;
        }

        $flags = json_decode($row->feature_flags, true) ?? [];
        $flags['post_detail_overlay_mode'] = false;

        DB::table('project_settings')
            ->where('id', 1)
            ->update(['feature_flags' => json_encode($flags)]);
    }

    public function down(): void
    {
        $row = DB::table('project_settings')->find(1);
        if (! $row) {
            return;
        }

        $flags = json_decode($row->feature_flags, true) ?? [];
        unset($flags['post_detail_overlay_mode']);

        DB::table('project_settings')
            ->where('id', 1)
            ->update(['feature_flags' => json_encode($flags)]);
    }
};
