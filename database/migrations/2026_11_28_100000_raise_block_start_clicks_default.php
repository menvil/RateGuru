<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A weak start is judged once a site has had 1,000 clicks, not 60: on
        // pop traffic a few dozen or hundred clicks bring a start or two at
        // most, too few to tell a weak site from an unlucky one. A settings
        // row still on the old default takes the new one; a value an
        // administrator chose stays, and so does a source's own.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedInteger('block_start_clicks')->default(1000)->change();
        });

        DB::table('traffic_settings')->where('block_start_clicks', 60)->update(['block_start_clicks' => 1000]);
    }

    public function down(): void
    {
        // Only the default goes back: a row at 1,000 may be one an administrator chose, and 1,000 is a value the
        // rule took before this change as well.
        Schema::table('traffic_settings', function (Blueprint $table) {
            $table->unsignedInteger('block_start_clicks')->default(60)->change();
        });
    }
};
