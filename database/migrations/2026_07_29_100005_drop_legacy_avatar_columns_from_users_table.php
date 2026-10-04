<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destructive by design: users now reference their avatar through
 * avatar_asset_id -> media_assets. There is no production data to preserve
 * (staging only), so this drops the legacy columns outright instead of
 * dual-writing/backfilling. See docs/architecture/media.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_url']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_url')->nullable();
            $table->string('avatar_path')->nullable();
        });
    }
};
