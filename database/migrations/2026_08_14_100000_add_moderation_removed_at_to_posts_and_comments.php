<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hidden + moderation_removed_at IS NULL  -> reversible moderation hide
        // Hidden + moderation_removed_at NOT NULL -> finalized moderation removal
        // No backfill: existing Hidden rows stay reversible. Actor/reason live
        // in moderation_logs, never on the content row.
        Schema::table('posts', function (Blueprint $table) {
            $table->timestamp('moderation_removed_at')->nullable()->after('deleted_at');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->timestamp('moderation_removed_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('moderation_removed_at');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('moderation_removed_at');
        });
    }
};
