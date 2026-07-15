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
        $flags['show_follow_buttons'] = true;

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
        $flags['show_follow_buttons'] = false;

        DB::table('project_settings')
            ->where('id', 1)
            ->update(['feature_flags' => json_encode($flags)]);
    }
};
