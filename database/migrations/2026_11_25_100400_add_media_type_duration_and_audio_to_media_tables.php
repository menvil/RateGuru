<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What an asset is (image, video, animation) beside what it is for
        // (kind), how long it plays and whether it carries sound — for the
        // video and animated-GIF uploads the media pipeline is about to take
        // (docs/architecture/media.md, "Media type, duration and sound").
        // Every asset stored so far is an image, which the column's default
        // says. A constant default and two nullable or defaulted columns are
        // added in place on every engine, waited for briefly, never behind a
        // long write.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("set local lock_timeout = '5s'");
        }

        Schema::table('media_assets', function (Blueprint $table) {
            $table->string('media_type')->default('image');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('has_audio')->default(false);
        });

        // A video variant plays for as long as its master, give or take the
        // encoder's last frame, so it records its own duration.
        Schema::table('media_variants', function (Blueprint $table) {
            $table->unsignedInteger('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media_variants', function (Blueprint $table) {
            $table->dropColumn('duration_ms');
        });

        Schema::table('media_assets', function (Blueprint $table) {
            $table->dropColumn(['media_type', 'duration_ms', 'has_audio']);
        });
    }
};
