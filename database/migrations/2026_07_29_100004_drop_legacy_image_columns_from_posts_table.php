<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Destructive by design: posts now reference their image through
 * image_asset_id -> media_assets. There is no production data to preserve
 * (staging only), so this drops the legacy columns outright instead of
 * dual-writing/backfilling. See docs/architecture/media.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_url', 'thumbnail_url', 'og_image_path']);
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('og_image_path')->nullable();
        });
    }
};
