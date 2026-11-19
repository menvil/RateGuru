<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A site is judged for bots once it has had 100 clicks, not 30: on pop
        // traffic a few dozen clicks say too little. A settings row still on
        // the old default takes the new one; a value an administrator chose stays.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedInteger('block_bot_clicks')->default(100)->change();
        });

        DB::table('traffic_settings')->where('block_bot_clicks', 30)->update(['block_bot_clicks' => 100]);
    }

    public function down(): void
    {
        DB::table('traffic_settings')->where('block_bot_clicks', 100)->update(['block_bot_clicks' => 30]);

        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedInteger('block_bot_clicks')->default(30)->change();
        });
    }
};
